<?php

namespace KimaiPlugin\MileageBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Reason for sending a handed-in month back to the user (required).
 */
class RejectMonthForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('comment', TextareaType::class, [
            'label' => 'mileage.approval.reason',
            'help' => 'mileage.approval.reason_help',
            'constraints' => [new NotBlank(message: 'mileage.approval.error.reason'), new Length(max: 2000)],
            'attr' => ['rows' => 3, 'maxlength' => 2000],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'messages']);
    }
}
