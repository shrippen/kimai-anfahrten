<?php

namespace KimaiPlugin\MileageBundle\Tests\Form;

use KimaiPlugin\MileageBundle\Form\Type\SecretType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

class SecretTypeTest extends TestCase
{
    public function testStoredSecretIsNeitherRenderedNorLostOnEmptySubmit(): void
    {
        $factory = Forms::createFormFactory();

        $form = $factory->create(SecretType::class, 'secret-key');
        $view = $form->createView();
        self::assertSame('', $view->vars['value']);
        self::assertSame('password', $view->vars['type']);

        $form->submit('');
        self::assertSame('secret-key', $form->getData());

        $form = $factory->create(SecretType::class, 'secret-key');
        $form->submit('new-key');
        self::assertSame('new-key', $form->getData());

        $form = $factory->create(SecretType::class, null);
        $form->submit('');
        self::assertNull($form->getData());
    }
}
