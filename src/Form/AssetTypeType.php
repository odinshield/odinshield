<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\AssetType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AssetType>
 */
class AssetTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var AssetType|null $entity */
        $entity = $options['data'];
        $builder
            ->add('lockVersion', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => null !== $entity ? (string) $entity->getLockVersion() : '1',
            ])
            ->add('name', TextType::class, [
                'empty_data' => '',
                'label' => 'asset_type.field.name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'asset_type.field.description',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'asset_type.field.active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssetType::class,
        ]);
    }
}
