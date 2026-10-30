<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\Vendor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Vendor>
 */
class VendorQuickCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'vendor.field.name',
                'required' => true,
            ])
            ->add('vendorKey', TextType::class, [
                'label' => 'vendor.field.vendor_key',
                'required' => true,
                'help' => 'vendor.field.vendor_key_help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Vendor::class,
            // CSRF is intentionally disabled here: this form type is only ever
            // used inside the VendorQuickCreateForm Live Component, embedded
            // within SoftwareForm. The Live Component framework validates its own
            // CSRF token on every #[LiveAction] call (LiveComponentSubscriber).
            // Enabling Symfony's form-level CSRF would break every save action
            // because the hidden _token is never re-emitted on re-renders.
            // The endpoint is protected by ROLE_SOFTWARE_OWNER or ROLE_ISB (SEC-006).
            'csrf_protection' => false,
        ]);
    }
}
