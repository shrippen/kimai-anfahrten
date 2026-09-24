<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\DatePickerType;
use App\Form\Type\ProjectType;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TripForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DatePickerType::class, [
                'label' => 'trip.date',
                'input' => 'datetime_immutable',
            ])
            ->add('purpose', EnumType::class, [
                'class' => TripPurpose::class,
                'label' => 'trip.purpose',
                'help' => 'trip.purpose_help',
                'choice_label' => fn (TripPurpose $purpose) => $purpose->label(),
                'choice_attr' => fn (TripPurpose $purpose) => ['data-icon' => $purpose->icon()],
                'expanded' => true,
            ])
            ->add('vehicle', EnumType::class, [
                'class' => VehicleType::class,
                'label' => 'trip.vehicle',
                'help' => 'trip.vehicle_help',
                'choice_label' => fn (VehicleType $vehicle) => $vehicle->label(),
            ])
            ->add('licensePlate', TextType::class, [
                'label' => 'trip.license_plate',
                'required' => false,
            ])
            ->add('departureAt', DateTimeType::class, [
                'label' => 'trip.departure',
                // Kimai loads datetimes as UTC; the view uses the user's timezone.
                'model_timezone' => 'UTC',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('arrivalAt', DateTimeType::class, [
                'label' => 'trip.arrival',
                // Kimai loads datetimes as UTC; the view uses the user's timezone.
                'model_timezone' => 'UTC',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'trip.time_window_help',
            ])
            ->add('startLocation', TextType::class, [
                'label' => 'trip.start_location',
                'required' => false,
            ])
            ->add('destination', TextType::class, [
                'label' => 'trip.destination',
                'required' => false,
            ])
            ->add('distanceKm', NumberType::class, [
                'label' => 'trip.distance',
                'help' => 'trip.distance_help',
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 0.1],
            ])
            ->add('roundTrip', CheckboxType::class, [
                'label' => 'trip.round_trip',
                'required' => false,
            ])
            ->add('costs', NumberType::class, [
                'label' => 'trip.costs',
                'help' => 'trip.costs_help',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 0.01],
            ])
            ->add('project', ProjectType::class, [
                'label' => 'trip.project',
                'required' => false,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'trip.comment',
                'required' => false,
            ]);

        if ($options['dawarich']) {
            $builder->add('dawarich', SubmitType::class, [
                'label' => 'dawarich.lookup',
                'validation_groups' => false,
                'attr' => ['class' => 'btn btn-outline-info'],
            ]);
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'action.save',
            'attr' => ['class' => 'btn btn-primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Trip::class,
            'translation_domain' => 'messages',
            'choice_translation_domain' => 'messages',
            'dawarich' => false,
        ]);
        $resolver->setAllowedTypes('dawarich', 'bool');
    }
}
