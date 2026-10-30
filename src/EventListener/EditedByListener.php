<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::onFlush)]
class EditedByListener
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!method_exists($entity, 'setCreatedBy') || !method_exists($entity, 'setUpdatedBy')) {
            return;
        }

        // Audit L6: with no security token (CLI/worker) leave the columns at their default rather
        // than stamping a misleading null author.
        $username = $this->getCurrentUsername();
        if (null === $username) {
            return;
        }
        $entity->setCreatedBy($username);
        $entity->setUpdatedBy($username);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!method_exists($entity, 'setUpdatedBy')) {
                continue;
            }

            // Audit L6: don't wipe a previous editor with null when a token-less process (CSAF
            // importer, CLI) updates the row — leave updatedBy as it was.
            $username = $this->getCurrentUsername();
            if (null === $username) {
                continue;
            }
            $entity->setUpdatedBy($username);
            $uow->recomputeSingleEntityChangeSet(
                $em->getClassMetadata($entity::class),
                $entity,
            );
        }
    }

    private function getCurrentUsername(): ?string
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return null;
        }

        return $user->getUserIdentifier();
    }
}
