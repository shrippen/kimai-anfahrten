<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\CustomerType;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlaceForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'mileage.place.name'])
            ->add('type', EnumType::class, [
                'class' => PlaceType::class,
                'label' => 'mileage.place.type',
                'choice_label' => fn (PlaceType $type) => $type->label(),
            ])
            ->add('address', TextType::class, ['label' => 'mileage.place.address', 'required' => false])
            ->add('latitude', NumberType::class, ['label' => 'mileage.place.latitude', 'scale' => 6, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('longitude', NumberType::class, ['label' => 'mileage.place.longitude', 'scale' => 6, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('radius', IntegerType::class, ['label' => 'mileage.place.radius', 'help' => 'mileage.place.radius_help'])
            ->add('customer', CustomerType::class, ['label' => 'mileage.place.customer', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Place::class,
            'translation_domain' => 'messages',
            'choice_translation_domain' => 'messages',
        ]);
    }
}
