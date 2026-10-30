<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Validator;

use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Enum\VulnerabilityStatus;
use App\Validator\Constraints\TerminalStatusRequiresTerminalOccurrences;
use App\Validator\Constraints\TerminalStatusRequiresTerminalOccurrencesValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<TerminalStatusRequiresTerminalOccurrencesValidator>
 */
final class TerminalStatusRequiresTerminalOccurrencesValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new TerminalStatusRequiresTerminalOccurrencesValidator();
    }

    public function testNonTerminalStatusIsAlwaysValid(): void
    {
        $vuln = $this->vulnerability(VulnerabilityStatus::InProgress, [VulnerabilityStatus::Open]);

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->assertNoViolation();
    }

    public function testTerminalStatusWithoutOccurrencesIsValid(): void
    {
        $vuln = $this->vulnerability(VulnerabilityStatus::Closed, []);

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->assertNoViolation();
    }

    public function testTerminalStatusWithAllTerminalOccurrencesIsValid(): void
    {
        $vuln = $this->vulnerability(
            VulnerabilityStatus::Closed,
            [VulnerabilityStatus::Resolved, VulnerabilityStatus::Accepted, VulnerabilityStatus::Closed],
        );

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->assertNoViolation();
    }

    /**
     * Issue #55: a group that was checked and found not affected has nothing left to do —
     * it must not keep the finding from being closed.
     */
    public function testNotAffectedOccurrenceDoesNotBlockClosure(): void
    {
        $vuln = $this->vulnerability(
            VulnerabilityStatus::Closed,
            [VulnerabilityStatus::Resolved, VulnerabilityStatus::NotAffected],
        );

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->assertNoViolation();
    }

    public function testDeferredOccurrenceCountsAsOpen(): void
    {
        $vuln = $this->vulnerability(
            VulnerabilityStatus::Resolved,
            [VulnerabilityStatus::Resolved, VulnerabilityStatus::Deferred],
        );

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->buildViolation('vulnerability.status.occurrences_not_terminal')
            ->setParameter('%count%', '1')
            ->setPlural(1)
            ->atPath('property.path.status')
            ->assertRaised();
    }

    public function testTerminalStatusWithOpenOccurrencesIsRejected(): void
    {
        $vuln = $this->vulnerability(
            VulnerabilityStatus::Closed,
            [VulnerabilityStatus::Open, VulnerabilityStatus::InProgress, VulnerabilityStatus::Resolved],
        );

        $this->validator->validate($vuln, new TerminalStatusRequiresTerminalOccurrences());

        $this->buildViolation('vulnerability.status.occurrences_not_terminal')
            ->setParameter('%count%', '2')
            ->setPlural(2)
            ->atPath('property.path.status')
            ->assertRaised();
    }

    /**
     * @param list<VulnerabilityStatus> $occurrenceStatuses
     */
    private function vulnerability(VulnerabilityStatus $status, array $occurrenceStatuses): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setStatus($status);

        foreach ($occurrenceStatuses as $occurrenceStatus) {
            $occurrence = new VulnerabilityOccurrence();
            $occurrence->setStatus($occurrenceStatus);
            $vuln->addOccurrence($occurrence);
        }

        return $vuln;
    }
}
