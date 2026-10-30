<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App;

use App\Message\RunUpdateCheckMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire('%app.update_check.schedule_cron%')]
        private string $updateCheckCron,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            // #223: daily update check, UTC like the importer schedules. On the default schedule
            // because `scheduler_default` is already in every worker's consume list. The handler
            // returns at once when UPDATE_CHECK_ENABLED=0.
            ->add(RecurringMessage::cron($this->updateCheckCron, new RunUpdateCheckMessage(), new \DateTimeZone('UTC')))

            // add your own tasks here
            // see https://symfony.com/doc/current/scheduler.html#attaching-recurring-messages-to-a-schedule
        ;
    }
}
