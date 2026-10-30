<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\Vendor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Vendor>
 */
class VendorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Vendor|null $entity */
        $entity = $options['data'];
        $builder
            ->add('lockVersion', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => null !== $entity ? (string) $entity->getLockVersion() : '1',
            ])
            ->add('name', TextType::class, [
                'empty_data' => '',
                'label' => 'vendor.field.name',
                'required' => true,
            ])
            ->add('vendorKey', TextType::class, [
                'empty_data' => '',
                'label' => 'vendor.field.vendor_key',
                'required' => true,
                'help' => 'vendor.field.vendor_key_help',
            ])
            ->add('aliases', AliasListType::class, [
                'label' => 'vendor.field.aliases',
                'help' => 'vendor.field.aliases_help',
                'attr' => ['placeholder' => 'vendor.field.aliases_placeholder'],
            ])
            ->add('website', UrlType::class, [
                'label' => 'vendor.field.website',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('advisoryUrl', UrlType::class, [
                'label' => 'vendor.field.advisory_url',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'vendor.field.active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Vendor::class,
        ]);
    }
}
