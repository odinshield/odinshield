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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/**
 * @extends AbstractType<User>
 */
class UserSettingsType extends AbstractType
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
            ->add('emailNotifications', CheckboxType::class, [
                'label' => 'user.field.email_notifications',
                'required' => false,
            ])
            // SEC-001: Require the active credential before any password or e-mail change.
            // Unmapped → never written to the entity; the UserPassword constraint
            // is only enforced when plainPassword is non-empty or the e-mail differs (added at submit
            // time via FormEvents::PRE_SUBMIT so legitimate non-password edits
            // are not blocked).
            ->add('currentPassword', PasswordType::class, [
                'label' => 'user_settings.current_password.label',
                'help' => 'user_settings.current_password.help',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'user.field.password',
                    'required' => false,
                    'attr' => ['autocomplete' => 'new-password'],
                    // #239: name the policy here too, not only on the forced first change.
                    'help' => 'user.field.password_help_policy',
                    'help_translation_parameters' => ['%policy%' => $this->passwordPolicy->hint($this->translator)],
                ],
                'second_options' => [
                    'label' => 'user.field.password_confirm',
                    'required' => false,
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'user.password.mismatch',
                'constraints' => [
                    new PasswordComplexity(),
                ],
            ])
        ;

        // SEC-001: Only validate currentPassword when the user actually tries
        // to change their password or e-mail address (#193 — a hijacked session
        // must not redirect assignment mails). Avoids forcing a re-auth on
        // cosmetic edits (locale, theme, email notifications). The controller
        // reads the resulting `required` flag to charge the rate limiter.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            $form = $event->getForm();
            $user = $form->getData();

            $plain = $data['plainPassword']['first'] ?? '';
            $email = \is_string($data['email'] ?? null) ? trim($data['email']) : '';
            $emailChanged = $user instanceof User && $email !== $user->getEmail();
            if ('' === $plain && !$emailChanged) {
                return;
            }

            $form->add('currentPassword', PasswordType::class, [
                'label' => 'user_settings.current_password.label',
                'help' => 'user_settings.current_password.help',
                'mapped' => false,
                'required' => true,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new UserPassword(message: 'user_settings.current_password.invalid'),
                ],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
