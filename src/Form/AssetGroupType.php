<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Enum\AssetCriticality;
use App\Enum\AssetExposure;
use App\Enum\DataSensitivity;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AssetGroup>
 */
class AssetGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = $options['data'] ?? null;
        $currentResponsibleUserId = $data instanceof AssetGroup ? $data->getResponsibleUser()?->getId() : null;
        $currentAssetTypeId = null;
        if ($data instanceof AssetGroup) {
            try {
                $currentAssetTypeId = $data->getAssetType()->getId();
            } catch (\Error) {
                // uninitialized property on new entity
            }
        }
        $currentSoftwareIds = $data instanceof AssetGroup
            ? $data->getSoftware()->map(static fn (Software $s) => $s->getId())->toArray()
            : [];

        $builder
            ->add('lockVersion', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $data instanceof AssetGroup ? (string) $data->getLockVersion() : '1',
            ])
            ->add('name', TextType::class, [
                'label' => 'asset_group.field.name',
                'required' => true,
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'asset_group.field.description',
                'required' => false,
            ])
            ->add('assetType', EntityType::class, [
                'class' => AssetType::class,
                'choice_label' => 'name',
                'label' => 'asset_group.field.asset_type',
                'placeholder' => 'asset_group.field.asset_type_placeholder',
                'required' => true,
                ...RequiredChoice::options('asset_group.asset_type.not_null'),
                'choice_attr' => static fn (AssetType $at) => $at->isActive() ? [] : ['data-inactive' => 'true'],
                'query_builder' => static function ($repo) use ($currentAssetTypeId) {
                    $qb = $repo->createQueryBuilder('at')
                        ->where('at.active = :active')
                        ->setParameter('active', true);
                    if (null !== $currentAssetTypeId) {
                        $qb->orWhere('at.id = :currentAssetTypeId')
                            ->setParameter('currentAssetTypeId', $currentAssetTypeId);
                    }

                    return $qb->orderBy('at.name', \SortDirection::Ascending);
                },
            ])
            ->add('criticality', EnumType::class, [
                'label' => 'asset_group.field.criticality',
                'class' => AssetCriticality::class,
                'choice_label' => fn (AssetCriticality $c) => 'asset_criticality.'.$c->value,
                'help' => 'asset_group.field.criticality_help',
                ...RequiredChoice::options(),
            ])
            ->add('exposure', EnumType::class, [
                'label' => 'asset_group.field.exposure',
                'class' => AssetExposure::class,
                'choice_label' => fn (AssetExposure $e) => 'asset_exposure.'.$e->value,
                'help' => 'asset_group.field.exposure_help',
                ...RequiredChoice::options(),
            ])
            ->add('dataSensitivity', EnumType::class, [
                'label' => 'asset_group.field.data_sensitivity',
                'class' => DataSensitivity::class,
                'choice_label' => fn (DataSensitivity $d) => 'data_sensitivity.'.$d->value,
                'help' => 'asset_group.field.data_sensitivity_help',
                ...RequiredChoice::options(),
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'asset_group.field.active',
                'required' => false,
            ])
            ->add('software', EntityType::class, [
                'class' => Software::class,
                'choice_label' => static fn (Software $s) => sprintf('%s — %s %s', $s->getVendor()->getName(), $s->getName(), $s->getVersion()),
                'label' => 'asset_group.field.software',
                'required' => false,
                'multiple' => true,
                'choice_attr' => static fn (Software $s) => $s->isActive() ? [] : ['data-inactive' => 'true'],
                'query_builder' => static function ($repo) use ($currentSoftwareIds) {
                    $qb = $repo->createQueryBuilder('s')
                        ->join('s.vendor', 'v')
                        ->where('s.active = :active')
                        ->setParameter('active', true);
                    if ([] !== $currentSoftwareIds) {
                        $qb->orWhere('s.id IN (:currentSoftwareIds)')
                            ->setParameter('currentSoftwareIds', $currentSoftwareIds);
                    }

                    return $qb->orderBy('v.name', \SortDirection::Ascending)->addOrderBy('s.name', \SortDirection::Ascending);
                },
            ])
            ->add('responsibleUser', EntityType::class, [
                'class' => User::class,
                'choice_label' => fn (User $u) => $u->getFirstName().' '.$u->getLastName().' ('.$u->getUsername().')',
                'label' => 'asset_group.field.responsible_user',
                'placeholder' => 'asset_group.field.responsible_user_placeholder',
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssetGroup::class,
        ]);
    }
}
