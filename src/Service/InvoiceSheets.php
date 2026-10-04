<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class InvoiceSheets
{
    public function __construct(
        private HttpClientInterface $http,
        private string $credentialsFile = '',
        private string $spreadsheetId = '',
        private string $tabName = 'Factures fournisseurs',
    ) {}

    public function configured(): bool
    {
        return $this->credentialsFile !== '' && $this->spreadsheetId !== '';
    }

    public function send(array $rows): int
    {
        if (!$this->configured()) { throw new \RuntimeException('Configurer le fichier du compte de service et le document Google Sheets.'); }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $this->spreadsheetId) || $this->tabName === '' || mb_strlen($this->tabName) > 100 || preg_match('/[\\\\\/\?\*\[\]:\x00-\x1f]/u', $this->tabName)) {
            throw new \RuntimeException('Identifiant du document ou nom de l’onglet Google Sheets invalide.');
        }
        $token = $this->token();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.$this->spreadsheetId;
        $metadata = $this->request('GET', $base, $token, ['query'=>['fields'=>'sheets.properties.title']]);
        if (!is_array($metadata['sheets'] ?? null)) { throw new \RuntimeException('Réponse Google Sheets invalide.'); }
        $exists = false;
        foreach ($metadata['sheets'] as $sheet) { if (($sheet['properties']['title'] ?? null) === $this->tabName) { $exists = true; } }
        if (!$exists) { $this->request('POST', $base.':batchUpdate', $token, ['json'=>['requests'=>[['addSheet'=>['properties'=>['title'=>$this->tabName]]]]]]); }
        $tab = "'".str_replace("'", "''", $this->tabName)."'";
        $range = rawurlencode($tab.'!A1:'.chr(64 + count(InvoiceLedger::HEADERS)).'1');
        $header = $this->request('GET', $base.'/values/'.$range, $token);
        $values = $header['values'] ?? [];
        if ($values === []) {
            if ($exists) {
                $unheaded = $this->request('GET', $base.'/values/'.rawurlencode($tab.'!A2:'.chr(64 + count(InvoiceLedger::HEADERS))), $token);
                if (($unheaded['values'] ?? []) !== []) { throw new \RuntimeException('L’onglet Google Sheets contient des données sans en-tête. Choisir un onglet dédié vide.'); }
            }
            $this->request('PUT', $base.'/values/'.$range, $token, ['query'=>['valueInputOption'=>'RAW'], 'json'=>['values'=>[InvoiceLedger::HEADERS]]]);
        } elseif ($values !== [InvoiceLedger::HEADERS]) {
            throw new \RuntimeException('L’onglet Google Sheets possède des colonnes différentes. Choisir un onglet dédié vide.');
        }
        $existing = $this->request('GET', $base.'/values/'.rawurlencode($tab.'!A2:A'), $token);
        if (isset($existing['values']) && !is_array($existing['values'])) { throw new \RuntimeException('Réponse Google Sheets invalide.'); }
        $ids = [];
        foreach ($existing['values'] ?? [] as $value) {
            if (!is_array($value) || (isset($value[0]) && !is_scalar($value[0]))) { throw new \RuntimeException('Réponse Google Sheets invalide.'); }
            if (isset($value[0])) { $ids[(string)$value[0]] = true; }
        }
        $missing = [];
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            if (!isset($ids[$id])) { $missing[] = InvoiceLedger::exportRow($row); $ids[$id] = true; }
        }
        if (!$missing) { return 0; }
        // RAW keeps supplier names and references beginning with '=' as literal text.
        $result = $this->request('POST', $base.'/values/'.rawurlencode($tab.'!A:'.chr(64 + count(InvoiceLedger::HEADERS))).':append', $token, [
            'query'=>['valueInputOption'=>'RAW', 'insertDataOption'=>'INSERT_ROWS'], 'json'=>['values'=>$missing],
        ]);
        if (($result['updates']['updatedRows'] ?? null) !== count($missing)) { throw new \RuntimeException('Résultat Google Sheets incertain. Relancer l’envoi pour vérifier les identifiants déjà exportés.'); }
        return count($missing);
    }

    private function token(): string
    {
        try {
            if (!is_file($this->credentialsFile) || !is_readable($this->credentialsFile)) { throw new \RuntimeException(); }
            $credentials = json_decode(file_get_contents($this->credentialsFile), true, 32, JSON_THROW_ON_ERROR);
            if (($credentials['type'] ?? '') !== 'service_account' || !is_string($credentials['client_email'] ?? null) || !filter_var($credentials['client_email'], FILTER_VALIDATE_EMAIL) || !is_string($credentials['private_key'] ?? null)) { throw new \RuntimeException(); }
            $key = openssl_pkey_get_private($credentials['private_key']);
            if (!$key || openssl_pkey_get_details($key)['type'] !== OPENSSL_KEYTYPE_RSA) { throw new \RuntimeException(); }
            $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $now = time();
            $jwt = $encode(json_encode(['alg'=>'RS256', 'typ'=>'JWT'], JSON_THROW_ON_ERROR)).'.'.$encode(json_encode([
                'iss'=>$credentials['client_email'], 'scope'=>'https://www.googleapis.com/auth/spreadsheets',
                'aud'=>'https://oauth2.googleapis.com/token', 'iat'=>$now, 'exp'=>$now + 3600,
            ], JSON_THROW_ON_ERROR));
            if (!openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256)) { throw new \RuntimeException(); }
        } catch (\Throwable) { throw new \RuntimeException('Fichier du compte de service Google invalide ou illisible.'); }
        $response = $this->request('POST', 'https://oauth2.googleapis.com/token', '', ['body'=>[
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion'=>$jwt.'.'.$encode($signature),
        ]]);
        if (!is_string($response['access_token'] ?? null) || $response['access_token'] === '' || preg_match('/[\r\n]/', $response['access_token'])) { throw new \RuntimeException('Authentification Google indisponible.'); }
        return $response['access_token'];
    }

    private function request(string $method, string $url, string $token, array $options = []): array
    {
        try {
            $options += ['max_redirects'=>0, 'timeout'=>10, 'max_duration'=>30];
            if ($token !== '') { $options['auth_bearer'] = $token; }
            $response = $this->http->request($method, $url, $options);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) { throw new \RuntimeException(); }
            return $response->toArray(false);
        } catch (\Throwable) { throw new \RuntimeException('Envoi Google Sheets indisponible. Les factures restent enregistrées ; relancer l’envoi pour vérifier les identifiants déjà exportés.'); }
    }
}
