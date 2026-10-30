<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\AssetType;
use App\Entity\User;
use App\Entity\UserGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UserGroup>
 */
class UserGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = $options['data'] ?? null;
        $currentUserIds = $data instanceof UserGroup
            ? $data->getUsers()->map(static fn (User $u) => $u->getId())->toArray()
            : [];

        $builder
            ->add('lockVersion', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $data instanceof UserGroup ? (string) $data->getLockVersion() : '1',
            ])
            ->add('name', TextType::class, [
                'empty_data' => '',
                'label' => 'user_group.field.name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'user_group.field.description',
                'required' => false,
            ])
            ->add('users', EntityType::class, [
                'class' => User::class,
                'choice_label' => static fn (User $u) => $u->getFullName() ?? $u->getUsername(),
                'label' => 'user_group.field.users',
                'required' => false,
                'multiple' => true,
                'query_builder' => static function ($repo) use ($currentUserIds) {
                    $qb = $repo->createQueryBuilder('u')
                        ->where('u.isActive = :active')
                        ->setParameter('active', true);
                    if ([] !== $currentUserIds) {
                        $qb->orWhere('u.id IN (:currentUserIds)')
                            ->setParameter('currentUserIds', $currentUserIds);
                    }

                    return $qb->orderBy('u.lastName', \SortDirection::Ascending)
                        ->addOrderBy('u.firstName', \SortDirection::Ascending)
                        ->addOrderBy('u.username', \SortDirection::Ascending);
                },
            ])
            ->add('assetTypes', EntityType::class, [
                'class' => AssetType::class,
                'choice_label' => 'name',
                'label' => 'user_group.field.asset_types',
                'required' => false,
                'multiple' => true,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('at')->orderBy('at.name', \SortDirection::Ascending),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserGroup::class,
        ]);
    }
}
