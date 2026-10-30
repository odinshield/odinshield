<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Entity\AssetGroup;
use App\Entity\Software;
use App\Entity\User;
use App\Enum\PredecessorAction;
use App\Enum\MaintenanceSource;
use App\Service\Software\SoftwareSuccessorService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single-page successor form: basics (mapped to draft Software), per-AG decision
 * (add new version / keep both / stay on old), predecessor action.
 *
 * @extends AbstractType<Software>
 */
final class SoftwareSuccessorType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AssetGroup> $assetGroups */
        $assetGroups = $options['asset_groups'];

        $data = $options['data'] ?? null;
        $currentResponsibleUserId = $data instanceof Software ? $data->getResponsibleUser()?->getId() : null;

        $builder
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

        $agChoices = [
            'software.successor.choice.migrate' => SoftwareSuccessorService::AG_MIGRATE,
            'software.successor.choice.both' => SoftwareSuccessorService::AG_BOTH,
            'software.successor.choice.keep' => SoftwareSuccessorService::AG_KEEP,
        ];
        foreach ($assetGroups as $assetGroup) {
            $builder->add('ag_choice_'.$assetGroup->getId(), ChoiceType::class, [
                'mapped' => false,
                'choices' => $agChoices,
                'expanded' => true,
                'multiple' => false,
                'label' => $assetGroup->getName(),
                'data' => SoftwareSuccessorService::AG_MIGRATE,
                'choice_translation_domain' => 'messages',
            ]);
        }

        // AG choices default to "migrate" for every group → initial form state is
        // "all AGs leave the predecessor", so default the action to "inactive".
        // Users override if they actually picked "keep" or "both" for some AGs.
        $builder->add('predecessor_action', EnumType::class, [
            'mapped' => false,
            'class' => PredecessorAction::class,
            'expanded' => true,
            'multiple' => false,
            'choice_label' => fn (PredecessorAction $a) => 'software.successor.predecessor.'.$a->value,
            'data' => PredecessorAction::Inactive,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Software::class,
            'csrf_protection' => true,
            'asset_groups' => [],
        ]);
        $resolver->setAllowedTypes('asset_groups', 'array');
    }
}
