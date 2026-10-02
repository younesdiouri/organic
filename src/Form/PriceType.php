<?php
namespace App\Form;
use App\Service\Money;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
final class PriceType extends AbstractType
{
    public function getParent(): string { return TextType::class; }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['label'=>'Montant (MAD)', 'attr'=>['inputmode'=>'decimal', 'placeholder'=>'0,00'], 'constraints'=>[
            new Assert\Callback(function($value, ExecutionContextInterface $context): void {
                if ($value === null || $value === '') { return; }
                try { Money::parse($value); } catch (\InvalidArgumentException $e) { $context->buildViolation($e->getMessage())->addViolation(); }
            })
        ]]);
    }
}
