<?php

namespace App\Service;

final class Money
{
    public const MAX = 100000000;

    public static function parse(string $value): int
    {
        $value = trim(str_replace(',', '.', $value));

        if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new \InvalidArgumentException('Montant invalide : utiliser au maximum deux décimales.');
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        if ($cents > self::MAX) {
            throw new \InvalidArgumentException('Montant trop élevé.');
        }

        return $cents;
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).','.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function csvCell(string $value): string
    {
        return preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
