<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\DatePickerType;
use App\Form\Type\DateTimePickerType;
use App\Form\Type\ProjectType;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
                'label' => 'mileage.trip.date',
                'input' => 'datetime_immutable',
            ])
            ->add('purpose', EnumType::class, [
                'class' => TripPurpose::class,
                'label' => 'mileage.trip.purpose',
                'help' => 'mileage.trip.purpose_help',
                'choice_label' => fn (TripPurpose $purpose) => $purpose->label(),
                'choice_attr' => fn (TripPurpose $purpose) => ['data-icon' => $purpose->icon()],
                'expanded' => true,
            ])
            ->add('vehicle', EnumType::class, [
                'class' => VehicleType::class,
                'label' => 'mileage.trip.vehicle',
                'help' => 'mileage.trip.vehicle_help',
                'choice_label' => fn (VehicleType $vehicle) => $vehicle->label(),
            ])
            ->add('assignedVehicle', EntityType::class, [
                'class' => Vehicle::class,
                'label' => 'mileage.vehicle.assigned',
                'help' => 'mileage.vehicle.assigned_help',
                'required' => false,
                'choices' => $options['vehicles'],
                'choice_label' => fn (Vehicle $vehicle) => $vehicle->getLabel(),
                'placeholder' => '',
            ])
            ->add('rental', EntityType::class, [
                'class' => Rental::class,
                'label' => 'mileage.rental.label',
                'help' => 'mileage.rental.trip_help',
                'required' => false,
                'choices' => $options['rentals'],
                'choice_label' => fn (Rental $rental) => $rental->getLabel(),
                'placeholder' => '',
            ])
            ->add('odometerStart', IntegerType::class, [
                'label' => 'mileage.odometer.start',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('odometerEnd', IntegerType::class, [
                'label' => 'mileage.odometer.end',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('licensePlate', TextType::class, [
                'label' => 'mileage.trip.license_plate',
                'required' => false,
            ])
            ->add('departureAt', DateTimePickerType::class, [
                'label' => 'mileage.trip.departure',
                // Kimai loads datetimes as UTC; the view uses the user's timezone.
                'model_timezone' => 'UTC',
                'required' => false,
            ])
            ->add('arrivalAt', DateTimePickerType::class, [
                'label' => 'mileage.trip.arrival',
                // Kimai loads datetimes as UTC; the view uses the user's timezone.
                'model_timezone' => 'UTC',
                'required' => false,
                'help' => 'mileage.trip.time_window_help',
            ])
            ->add('startLocation', TextType::class, [
                'label' => 'mileage.trip.start_location',
                'required' => false,
            ])
            ->add('destination', TextType::class, [
                'label' => 'mileage.trip.destination',
                'required' => false,
            ])
            ->add('distanceKm', NumberType::class, [
                'label' => 'mileage.trip.distance',
                'help' => 'mileage.trip.distance_help',
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 0.1],
            ])
            ->add('roundTrip', CheckboxType::class, [
                'label' => 'mileage.trip.round_trip',
                'required' => false,
            ])
            ->add('overnight', CheckboxType::class, [
                'label' => 'mileage.trip.overnight',
                'help' => 'mileage.trip.overnight_help',
                'required' => false,
            ])
            ->add('costs', NumberType::class, [
                'label' => 'mileage.trip.costs',
                'help' => 'mileage.trip.costs_help',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 0.01],
            ])
            ->add('project', ProjectType::class, [
                'label' => 'mileage.trip.project',
                'required' => false,
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'mileage.trip.comment',
                'required' => false,
            ]);

        // Kimai's date/time picker works with \DateTime, the entity with \DateTimeImmutable
        $immutable = new CallbackTransformer(
            static fn (?\DateTimeImmutable $value): ?\DateTime => $value !== null ? \DateTime::createFromImmutable($value) : null,
            static fn (?\DateTimeInterface $value): ?\DateTimeImmutable => $value !== null ? \DateTimeImmutable::createFromInterface($value) : null,
        );
        $builder->get('departureAt')->addModelTransformer($immutable);
        $builder->get('arrivalAt')->addModelTransformer($immutable);

        if ($options['dawarich']) {
            $builder->add('dawarich', SubmitType::class, [
                'label' => 'mileage.dawarich.lookup',
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
            'vehicles' => [],
            'rentals' => [],
        ]);
        $resolver->setAllowedTypes('dawarich', 'bool');
        $resolver->setAllowedTypes('vehicles', 'array');
        $resolver->setAllowedTypes('rentals', 'array');
    }
}
