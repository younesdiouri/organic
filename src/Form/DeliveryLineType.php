<?php
namespace App\Form;
use App\Entity\Product;
use App\Service\Money;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;
final class DeliveryLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('product', EntityType::class, ['class'=>Product::class, 'choice_label'=>fn(Product $product) => $product->name.' — '.Money::format($product->priceCents).' MAD', 'choice_attr'=>fn(Product $product) => ['data-price-cents'=>$product->priceCents], 'label'=>'Produit', 'placeholder'=>'Choisir', 'constraints'=>[new Assert\NotNull()]])
            ->add('quantity', IntegerType::class, ['label'=>'Quantité', 'constraints'=>[new Assert\NotBlank(), new Assert\Range(min: 1, max: 100000)], 'attr'=>['min'=>1,'max'=>100000]])
            ->add('price', PriceType::class, ['label'=>'Prix unitaire (MAD)', 'required'=>false, 'help'=>'Laisser vide pour le prix du catalogue.']);
    }
}
