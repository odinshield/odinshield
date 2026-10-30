<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Options for a required choice field (entity or enum) whose setter does not accept null.
 *
 * An empty submission would otherwise be written into the non-nullable setter during data
 * mapping — before validation runs — and die with a 500 (edit), or leave the property unset
 * and die on the NOT NULL column (new). The setter skips null, the field-level NotNull turns
 * the empty choice into an ordinary validation error on the field.
 */
final class RequiredChoice
{
    public const string DEFAULT_MESSAGE = 'form.choice.not_null';

    /**
     * @return array{setter: \Closure, constraints: list<NotNull>}
     */
    public static function options(string $message = self::DEFAULT_MESSAGE): array
    {
        return [
            'setter' => static function (object|array &$data, mixed $value, FormInterface $form): void {
                if (null === $value || null === $form->getPropertyPath()) {
                    return;
                }

                PropertyAccess::createPropertyAccessor()->setValue($data, $form->getPropertyPath(), $value);
            },
            'constraints' => [new NotNull(message: $message)],
        ];
    }
}
