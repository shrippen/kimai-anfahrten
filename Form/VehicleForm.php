<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\DatePickerType;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\PrivateUseMethod;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VehicleForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'mileage.vehicle.name'])
            ->add('type', EnumType::class, [
                'class' => VehicleType::class,
                'label' => 'mileage.trip.vehicle',
                'choice_label' => fn (VehicleType $type) => $type->label(),
            ])
            ->add('licensePlate', TextType::class, ['label' => 'mileage.trip.license_plate', 'required' => false])
            ->add('holder', TextType::class, ['label' => 'mileage.vehicle.holder', 'required' => false])
            ->add('validFrom', DatePickerType::class, ['label' => 'mileage.vehicle.valid_from', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('validTo', DatePickerType::class, ['label' => 'mileage.vehicle.valid_to', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('initialOdometer', IntegerType::class, ['label' => 'mileage.vehicle.initial_odometer', 'required' => false, 'attr' => ['min' => 0]])
            ->add('businessAsset', CheckboxType::class, ['label' => 'mileage.vehicle.business_asset', 'help' => 'mileage.vehicle.business_asset_help', 'required' => false])
            ->add('privateUse', EnumType::class, [
                'class' => PrivateUseMethod::class,
                'label' => 'mileage.vehicle.private_use',
                'help' => 'mileage.vehicle.private_use_help',
                'choice_label' => fn (PrivateUseMethod $method) => $method->label(),
            ])
            ->add('listPrice', NumberType::class, ['label' => 'mileage.vehicle.list_price', 'help' => 'mileage.vehicle.list_price_help', 'required' => false, 'scale' => 2, 'html5' => true, 'attr' => ['min' => 0, 'step' => 0.01]])
            ->add('listPriceFactor', ChoiceType::class, [
                'label' => 'mileage.vehicle.list_price_factor',
                'choices' => [
                    'mileage.vehicle.factor.full' => 1.0,
                    'mileage.vehicle.factor.hybrid' => 0.5,
                    'mileage.vehicle.factor.electric' => 0.25,
                ],
            ])
            ->add('active', CheckboxType::class, ['label' => 'mileage.vehicle.active', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Vehicle::class,
            'translation_domain' => 'messages',
            'choice_translation_domain' => 'messages',
        ]);
    }
}
