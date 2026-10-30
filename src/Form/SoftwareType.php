<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\Software;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\MaintenanceSource;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<Software>
 */
class SoftwareType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = $options['data'] ?? null;
        $currentResponsibleUserId = $data instanceof Software ? $data->getResponsibleUser()?->getId() : null;
        $currentVendorId = $data instanceof Software && $data->hasVendor() ? $data->getVendor()->getId() : null;

        $builder
            ->add('name', TextType::class, [
                'label' => 'software.field.name',
                'required' => true,
                'empty_data' => '',
            ])
            ->add('vendor', EntityType::class, [
                'label' => 'software.field.vendor',
                'class' => Vendor::class,
                'choice_label' => fn (Vendor $v) => $v->isActive() ? $v->getName() : sprintf('%s (%s)', $v->getName(), $this->translator->trans('common.badge.inactive')),
                'choice_attr' => static fn (Vendor $v) => $v->isActive() ? [] : ['data-inactive' => 'true'],
                // #213: keep the current vendor even when it was deactivated — otherwise the field
                // renders empty and a retired software (e.g. to set its EOL date) cannot be saved.
                'query_builder' => static function ($repository) use ($currentVendorId) {
                    $qb = $repository->createQueryBuilder('v')->where('v.active = true');
                    if (null !== $currentVendorId) {
                        $qb->orWhere('v.id = :currentVendorId')
                            ->setParameter('currentVendorId', $currentVendorId);
                    }

                    return $qb->orderBy('v.name', \SortDirection::Ascending);
                },
                'placeholder' => 'software.field.vendor_placeholder',
                'required' => true,
                ...RequiredChoice::options('software.vendor.not_null'),
            ])
            ->add('productKey', TextType::class, [
                'label' => 'software.field.product_key',
                'required' => true,
                'help' => 'software.field.product_key_help',
                'empty_data' => '',
            ])
            ->add('version', TextType::class, [
                'label' => 'software.field.version',
                'required' => true,
                'help' => 'software.field.version_help',
                'empty_data' => '',
            ])
            ->add('aliases', AliasListType::class, [
                'label' => 'software.field.aliases',
                'help' => 'software.field.aliases_help',
                'attr' => ['placeholder' => 'software.field.aliases_placeholder'],
            ])
            ->add('eolDate', DateType::class, [
                'label' => 'software.field.eol_date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => $this->translator->trans('software.field.eol_date_symfony_format'),
            ])
            ->add('maintenanceSource', EnumType::class, [
                'label' => 'software.field.maintenance_source',
                'class' => MaintenanceSource::class,
                'choice_label' => fn (MaintenanceSource $s) => 'maintenance_source.'.$s->value,
                'help' => 'software.field.maintenance_source_help',
                ...RequiredChoice::options(),
            ])
            ->add('responsibleUser', EntityType::class, [
                'class' => User::class,
                'choice_label' => fn (User $u) => $u->getFirstName().' '.$u->getLastName().' ('.$u->getUsername().')',
                'label' => 'software.field.responsible_user',
                'placeholder' => 'software.field.responsible_user_placeholder',
                'required' => false,
                'query_builder' => static function ($repo) use ($currentResponsibleUserId) {
                    $qb = $repo->createQueryBuilder('u')
                        ->where('u.isActive = :active')
                        ->setParameter('active', true);
                    if (null !== $currentResponsibleUserId) {
                        $qb->orWhere('u.id = :currentResponsibleUserId')
                            ->setParameter('currentResponsibleUserId', $currentResponsibleUserId);
                    }

                    return $qb->orderBy('u.lastName', \SortDirection::Ascending)->addOrderBy('u.firstName', \SortDirection::Ascending);
                },
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'software.field.active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Software::class,
            // CSRF is intentionally disabled here: this form type is only ever
            // used inside the SoftwareForm Live Component. The Live Component
            // framework validates its own CSRF token (via LiveComponentSubscriber)
            // on every #[LiveAction] call. Enabling Symfony's form-level CSRF
            // would generate a hidden _token field that the Live Component
            // re-rendering flow never re-emits, causing every save action to fail
            // with an invalid CSRF token error. The endpoint is protected by
            // IS_AUTHENTICATED_REMEMBERED at the class level (SEC-006).
            'csrf_protection' => false,
        ]);
    }
}
