<?php

namespace App\Service;

/** Reads cached values and formulas only; never executes spreadsheet content. */
final class CatalogueWorkbook
{
    private int $xmlBytes = 0;

    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 20_000_000) {
            throw new \InvalidArgumentException('Classeur absent ou trop volumineux.');
        }
        $zip = new \PharData($path);
        $this->xmlBytes = 0;
        $strings = [];

        if (isset($zip['xl/sharedStrings.xml'])) {
            foreach ($this->xml($zip, 'xl/sharedStrings.xml')->children()->si as $si) {
                $parts = $si->xpath('.//*[local-name()="t"]');
                $strings[] = implode('', array_map(static fn ($t) => (string) $t, $parts));
            }
        }
        $relationships = [];

        foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children() as $r) {
            $target = (string) $r['Target'];

            if ('External' === (string) $r['TargetMode'] || str_contains($target, '..')) {
                continue;
            }
            $relationships[(string) $r['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }
        $sheets = [];

        foreach ($this->xml($zip, 'xl/workbook.xml')->children()->sheets->sheet as $sheet) {
            if (count($sheets) >= 100) {
                throw new \InvalidArgumentException('Nombre d’onglets excessif.');
            }
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

            if (!isset($relationships[$id])) {
                throw new \InvalidArgumentException('Onglet sans relation locale.');
            }
            $rows = [];

            foreach ($this->xml($zip, $relationships[$id])->children()->sheetData->row as $row) {
                $number = (int) $row['r'];

                if ($number < 1 || $number > 10000) {
                    throw new \InvalidArgumentException('Nombre de lignes excessif.');
                }

                foreach ($row->c as $cell) {
                    $address = (string) $cell['r'];

                    if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/D', $address, $match)) {
                        throw new \InvalidArgumentException('Adresse de cellule incorrecte.');
                    }
                    $value = (string) $cell->v;
                    $type = (string) $cell['t'];

                    if ('s' === $type) {
                        if (!ctype_digit($value) || !isset($strings[(int) $value])) {
                            throw new \InvalidArgumentException('Chaîne partagée incorrecte.');
                        }
                        $value = $strings[(int) $value];
                    } elseif ('inlineStr' === $type) {
                        $value = implode('', array_map(static fn ($t) => (string) $t, $cell->xpath('.//*[local-name()="t"]')));
                    }

                    if (strlen($value) > 20000) {
                        throw new \InvalidArgumentException('Cellule trop longue.');
                    }

                    if ('' !== $value || isset($cell->f)) {
                        $rows[$number][$match[1]] = ['value' => $value, 'formula' => isset($cell->f) ? (string) $cell->f : null];
                    }
                }
            }
            $sheets[(string) $sheet['name']] = $rows;
        }

        return $sheets;
    }

    private function xml(\PharData $zip, string $name): \SimpleXMLElement
    {
        if (!isset($zip[$name]) || $zip[$name]->getSize() > 10_000_000) {
            throw new \InvalidArgumentException('Entrée XML absente ou trop volumineuse.');
        }
        $this->xmlBytes += $zip[$name]->getSize();

        if ($this->xmlBytes > 40_000_000) {
            throw new \InvalidArgumentException('Budget XML du classeur dépassé.');
        }
        $content = $zip[$name]->getContent();

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new \InvalidArgumentException('DTD et entités interdits.');
        }
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);

            if (!$xml) {
                throw new \InvalidArgumentException('XML invalide.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
