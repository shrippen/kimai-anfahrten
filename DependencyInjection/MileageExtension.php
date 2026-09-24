<?php

/*
 * This file is part of the MileageBundle plugin for Kimai.
 */

namespace KimaiPlugin\MileageBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class MileageExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $all = [
            'mileage',
            'edit_own_mileage',
            'edit_other_mileage',
            'delete_own_mileage',
            'delete_other_mileage',
            'view_other_mileage',
            'lock_mileage',
            'unlock_mileage',
            'edit_locked_mileage',
        ];

        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_SUPER_ADMIN' => $all,
                    'ROLE_ADMIN' => $all,
                    'ROLE_TEAMLEAD' => [
                        'mileage',
                        'edit_own_mileage',
                        'delete_own_mileage',
                        'view_other_mileage',
                        'lock_mileage',
                    ],
                    'ROLE_USER' => [
                        'mileage',
                        'edit_own_mileage',
                        'delete_own_mileage',
                        'lock_mileage',
                    ],
                ],
            ],
        ]);
    }
}
