<?php

namespace KimaiPlugin\MileageBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Commutes for the days with working time of one month (CommuteGenerator::suggestDays()).
 */
class CommuteForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, array{date: \DateTimeImmutable, hours: float, has_commute: bool}> $days */
        $days = $options['days'];

        $builder
            ->add('distance', NumberType::class, [
                'label' => 'mileage.commute.distance',
                'help' => 'mileage_commute_km_help',
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 0.1, 'step' => 0.1],
                'constraints' => [new NotBlank(), new Range(min: 0.1, max: 100000)],
            ])
            ->add('dates', ChoiceType::class, [
                'label' => 'mileage.commute.days',
                'choices' => array_combine(array_keys($days), array_keys($days)),
                'choice_attr' => static fn (string $key) => ($days[$key]['has_commute'] ?? false) ? ['disabled' => 'disabled'] : [],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
            'days' => [],
            'csrf_token_id' => 'mileage_commutes',
        ]);
        $resolver->setAllowedTypes('days', 'array');
    }
}
