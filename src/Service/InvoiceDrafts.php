<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class InvoiceDrafts
{
    public const TTL = 86400;
    public function __construct(#[Autowire('%kernel.project_dir%/var/invoice-drafts')] private string $root) {}
    public function create(int $owner, string $session, array $files = []): string
    {
        $this->cleanup();
        self::validateFiles($files);
        $files = array_values($files);
        $token = bin2hex(random_bytes(32));
        $dir = $this->root.'/'.$token;
        if (!is_dir($this->root)) { mkdir($this->root, 0700, true); }
        mkdir($dir, 0700);
        $draft = ['owner'=>$owner, 'session'=>hash('sha256', $session), 'created'=>time(), 'images'=>[], 'extraction'=>null];
        try {
            foreach ($files as $index=>$file) {
                $mime = mime_content_type($file->getPathname());
                $name = $index.'.'.['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'][$mime];
                $file->move($dir, $name);
                chmod($dir.'/'.$name, 0600);
                $draft['images'][] = ['name'=>$name, 'mime'=>$mime];
            }
            $this->write($token, $draft);
        } catch (\Throwable $e) {
            foreach (glob($dir.'/*') ?: [] as $path) { if (is_file($path) && !is_link($path)) { unlink($path); } }
            rmdir($dir);
            throw $e;
        }
        return $token;
    }
    public static function validateFiles(array $files): void
    {
        if (count($files)>6) { throw new \InvalidArgumentException('Maximum 6 photos par envoi.'); }
        $total = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) { throw new \InvalidArgumentException('Photo non reçue. Vérifier la taille du fichier.'); }
            $size = $file->getSize();
            $total += $size;
            $info = @getimagesize($file->getPathname());
            if ($size>8*1024*1024 || $total>20*1024*1024 || !in_array(mime_content_type($file->getPathname()), ['image/jpeg','image/png','image/webp'], true) || !$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) || $info[0]*$info[1]>40000000) {
                throw new \InvalidArgumentException('Photos JPEG, PNG ou WebP uniquement : 8 Mo par photo, 20 Mo au total, 40 mégapixels maximum.');
            }
        }
    }
    public function read(string $token, int $owner, string $session): array
    {
        $draft = $this->load($token);
        if ($draft['owner'] !== $owner || !hash_equals($draft['session'], hash('sha256', $session)) || $draft['created']+self::TTL<time()) { throw new \InvalidArgumentException('Brouillon inaccessible ou expiré. Reprendre la saisie.'); }
        return $draft;
    }
    private function load(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \InvalidArgumentException('Brouillon invalide.'); }
        $path = $this->root.'/'.$token.'/draft.json';
        if (!is_file($path) || is_link(dirname($path)) || is_link($path)) { throw new \InvalidArgumentException('Brouillon absent ou expiré.'); }
        $draft = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($draft) || !isset($draft['owner'], $draft['session'], $draft['created'], $draft['images'])) { throw new \InvalidArgumentException('Brouillon invalide.'); }
        return $draft;
    }
    public function write(string $token, array $draft): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \InvalidArgumentException('Brouillon invalide.'); }
        $path = $this->root.'/'.$token.'/draft.json';
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (!is_dir(dirname($path)) || is_link(dirname($path))) { throw new \RuntimeException('Brouillon déjà supprimé ou inaccessible. Reprendre la saisie.'); }
        if (@file_put_contents($temporary, json_encode($draft, JSON_THROW_ON_ERROR), LOCK_EX)===false) { throw new \RuntimeException('Écriture du brouillon impossible. Vérifier son stockage local.'); }
        chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Écriture du brouillon impossible. Vérifier son stockage local.');
        }
    }
    public function image(string $token, array $draft, int $index): string
    {
        $name = $draft['images'][$index]['name'] ?? '';
        if (!preg_match('/^[0-5]\.(jpg|png|webp)$/D', $name)) { throw new \InvalidArgumentException('Photo absente.'); }
        $path = $this->root.'/'.$token.'/'.$name;
        if (!is_file($path) || is_link($path)) { throw new \InvalidArgumentException('Photo absente.'); }
        return $path;
    }
    public function remove(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) && !is_file($this->root.'/'.$token.'/draft.json')) { return; }
        $draft = $this->load($token);
        foreach ($draft['images'] as $image) {
            if (!preg_match('/^[0-5]\.(jpg|png|webp)$/D', $image['name'])) { throw new \InvalidArgumentException('Photo invalide.'); }
            $path = $this->root.'/'.$token.'/'.$image['name'];
            if (is_link($path)) { throw new \InvalidArgumentException('Photo invalide.'); }
            if (is_file($path) && !@unlink($path) && is_file($path)) { throw new \RuntimeException('Suppression des photos impossible. Relancer le nettoyage des brouillons.'); }
        }
        $manifest = $this->root.'/'.$token.'/draft.json';
        if (is_file($manifest) && !@unlink($manifest) && is_file($manifest)) { throw new \RuntimeException('Suppression du brouillon impossible. Relancer son nettoyage.'); }
        @rmdir($this->root.'/'.$token);
    }
    public function cleanup(): int
    {
        $count = 0;
        foreach (glob($this->root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            try {
                $draft = $this->load($token);
                if ($draft['created']+self::TTL<time()) { $this->remove($token); ++$count; }
            } catch (\InvalidArgumentException|\JsonException) { /* Unowned directories are never deleted. */ }
        }
        return $count;
    }
}
