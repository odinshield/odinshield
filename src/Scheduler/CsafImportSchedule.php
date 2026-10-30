<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Scheduler;

use App\Message\RunCsafImportMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('csaf')]
final readonly class CsafImportSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire('%app.csaf.schedule_cron%')]
        private string $cronExpression,
    ) {
    }

    public function getSchedule(): Schedule
    {
        // Pin the cron interpretation to UTC. Without this argument Symfony Scheduler
        // uses the container's `TZ`, so an operator setting `TZ=Europe/Berlin` would
        // silently shift `0 3,15 * * *` from "03:00+15:00 UTC" (what the docs promise)
        // to "03:00+15:00 Berlin" (= 01:00+13:00 UTC) — and after 13:00 UTC the runner
        // would skip until next morning instead of firing at 15:00 UTC as intended.
        return (new Schedule())->add(
            RecurringMessage::cron($this->cronExpression, new RunCsafImportMessage(), new \DateTimeZone('UTC')),
        );
    }
}
