<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A comma-separated text input mapped to a `list<string>|null` model value — the alias lists on
 * Vendor and Software (#134, D7). Entries are trimmed and de-duplicated; an empty input maps to
 * null so an entity without aliases stays without aliases rather than carrying `[]`.
 *
 * @extends AbstractType<list<string>|null>
 */
final class AliasListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?array $list): string => null === $list ? '' : implode(', ', $list),
            static function (?string $text): ?array {
                $out = [];
                foreach (explode(',', (string) $text) as $piece) {
                    $piece = trim($piece);
                    if ('' !== $piece && !\in_array($piece, $out, true)) {
                        $out[] = $piece;
                    }
                }

                return [] === $out ? null : $out;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'empty_data' => '',
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
