<?php
namespace App\Service;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
final class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array { return [new TwigFilter('mad', fn($value) => Money::format((int)$value).' MAD')]; }
}
