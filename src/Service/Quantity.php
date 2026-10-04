<?php
namespace App\Service;

use Twig\Attribute\AsTwigFilter;

final class Quantity
{
    #[AsTwigFilter('quantity')]
    public static function format(?string $value): string
    {
        if ($value === null) { return ''; }
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
