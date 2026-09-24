<?php

namespace KimaiPlugin\MileageBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Text field rendered as password input: the stored value is kept (so saving the
 * preferences form does not wipe it) but it is not shown in clear text.
 */
class SecretType extends AbstractType
{
    public function getParent(): string
    {
        return TextType::class;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'password';
        $view->vars['attr']['autocomplete'] = 'off';
    }
}
