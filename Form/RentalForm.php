<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\DatePickerType;
use KimaiPlugin\MileageBundle\Entity\Rental;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RentalForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $money = ['required' => false, 'scale' => 2, 'html5' => true, 'attr' => ['min' => 0, 'step' => 0.01]];

        $builder
            ->add('provider', TextType::class, ['label' => 'mileage.rental.provider'])
            ->add('licensePlate', TextType::class, ['label' => 'mileage.trip.license_plate', 'required' => false])
            ->add('startDate', DatePickerType::class, ['label' => 'mileage.rental.start', 'input' => 'datetime_immutable'])
            ->add('endDate', DatePickerType::class, ['label' => 'mileage.rental.end', 'input' => 'datetime_immutable'])
            ->add('rentalCosts', NumberType::class, ['label' => 'mileage.rental.costs', 'help' => 'mileage.rental.costs_help'] + $money)
            ->add('fuelCosts', NumberType::class, ['label' => 'mileage.rental.fuel_costs'] + $money)
            ->add('comment', TextareaType::class, ['label' => 'mileage.trip.comment', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rental::class,
            'translation_domain' => 'messages',
        ]);
    }
}
