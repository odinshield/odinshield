<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use Symfony\Contracts\Translation\TranslatorInterface;
use App\Security\PasswordPolicy;
use App\Entity\User;
use App\Enum\Theme;
use App\Validator\Constraints\PasswordComplexity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<User>
 */
class UserType extends AbstractType
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $entity */
        $entity = $options['data'];
        $builder
            ->add('lockVersion', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => null !== $entity ? (string) $entity->getLockVersion() : '1',
            ])
            ->add('username', TextType::class, [
                'label' => 'user.field.username',
                'attr' => ['autocomplete' => 'username'],
            ])
            ->add('email', EmailType::class, [
                'empty_data' => '',
                'label' => 'user.field.email',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'user.field.first_name',
                'required' => true,
            ])
            ->add('lastName', TextType::class, [
                'label' => 'user.field.last_name',
                'required' => true,
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'user.field.locale',
                'choices' => [
                    'Deutsch' => 'de',
                    'English' => 'en',
                ],
                // Language endonyms display identically in every UI locale — do not
                // treat the literal choice labels as translation keys (would otherwise
                // surface as missing "Deutsch"/"English" message ids).
                'choice_translation_domain' => false,
            ])
            ->add('theme', EnumType::class, [
                'label' => 'user.field.theme',
                'class' => Theme::class,
                'choice_label' => fn (Theme $theme) => $theme->label(),
                'required' => false,
                'placeholder' => 'user.theme.system',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'user.field.roles',
                'choices' => [
                    'user.role.admin' => User::ROLE_ADMIN,
                    'user.role.software_owner' => User::ROLE_SOFTWARE_OWNER,
                    'user.role.hardware_owner' => User::ROLE_HARDWARE_OWNER,
                    'user.role.isb' => User::ROLE_ISB,
                ],
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'help' => 'user.field.roles_help',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'user.field.is_active',
                'required' => false,
            ])
            ->add('mustChangePassword', CheckboxType::class, [
                'label' => 'user.field.must_change_password',
                'required' => false,
            ])
            ->add('emailNotifications', CheckboxType::class, [
                'label' => 'user.field.email_notifications',
                'required' => false,
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['require_password'],
                'first_options' => [
                    'label' => 'user.field.password',
                    'required' => $options['require_password'],
                    'attr' => ['autocomplete' => 'new-password'],
                    // #239: both forms name the policy; editing adds "leave blank to keep".
                    'help' => $options['require_password'] ? 'password_policy.field_hint' : 'user.field.password_help_policy',
                    'help_translation_parameters' => ['%policy%' => $this->passwordPolicy->hint($this->translator)],
                ],
                'second_options' => [
                    'label' => 'user.field.password_confirm',
                    'required' => $options['require_password'],
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'user.password.mismatch',
                'constraints' => $options['require_password'] ? [
                    new NotBlank(message: 'user.password.not_blank'),
                    new PasswordComplexity(),
                ] : [
                    new PasswordComplexity(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
        ]);

        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
