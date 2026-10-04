<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'supplier')]
class Supplier
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 180)] public string $name = '';
    #[ORM\Column(type: 'json')] public array $aliases = [];
    #[ORM\Column(name: 'reporting_label', length: 180)] public string $reportingLabel = '';
    public static function normalize(string $name): string
    {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        return preg_replace('/[^a-z0-9]/', '', $name);
    }
}
