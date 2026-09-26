<?php

namespace KimaiPlugin\MileageBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Password input for a stored secret: the value is never sent to the browser, and submitting
 * the field empty keeps the stored value (so saving the preferences form does not wipe it).
 * A single space clears it (empty values count as "not configured").
 */
class SecretType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            if ($submitted === null || $submitted === '') {
                $stored = $event->getForm()->getData();
                $event->setData(\is_scalar($stored) ? (string) $stored : null);
            }
        }, 10); // before the trim listener, otherwise a single space would count as empty and keep the value
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'password';
        $view->vars['attr']['autocomplete'] = 'new-password';
        $view->vars['value'] = '';
        if (\is_scalar($form->getData()) && trim((string) $form->getData()) !== '') {
            $view->vars['attr']['placeholder'] = '••••••••';
        }
    }
}
