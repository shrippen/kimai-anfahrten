<?php

namespace KimaiPlugin\MileageBundle\Form;

use App\Form\Type\CustomerType;
use App\Form\Type\DateRangeType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Filter of the customer overview (GET, in the page header): Kimai date range picker and customer select.
 */
class OverviewFilterForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('daterange', DateRangeType::class, ['label' => false, 'required' => true, 'allow_empty' => false])
            ->add('customer', CustomerType::class, ['label' => false, 'required' => false, 'placeholder' => 'mileage.overview.all_customers']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
            'translation_domain' => 'messages',
            'attr' => ['class' => 'mileage-filter', 'data-mileage-autosubmit' => '1'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
