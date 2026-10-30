<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use App\Security\PasswordPolicy;
use App\Validator\Constraints\PasswordComplexity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<null>
 */
class ChangePasswordType extends AbstractType
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => true,
                'first_options' => [
                    'label' => 'user.field.password',
                    // #188: this is the forced first change — describe the policy the field is
                    // checked against, not the admin form's "leave blank to keep" hint.
                    'help' => 'password_policy.field_hint',
                    'help_translation_parameters' => ['%policy%' => $this->passwordPolicy->hint($this->translator)],
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'user.field.password_confirm',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'user.password.mismatch',
                'constraints' => [
                    new NotBlank(message: 'user.password.not_blank'),
                    new PasswordComplexity(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
