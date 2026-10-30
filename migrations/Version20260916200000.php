<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #125 — an actively exploited vulnerability doubles the effective-risk score
 * (`VulnerabilityOccurrence::ACTIVE_EXPLOITATION_FACTOR`). `effective_risk_score` is denormalised,
 * so existing occurrences of already-flagged vulnerabilities are brought in line here; from now on
 * `EffectiveRiskListener` keeps them in sync when the flag changes.
 */
final class Version20260916200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#125: double effective_risk_score for occurrences of actively exploited vulnerabilities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE vulnerability_occurrence o SET effective_risk_score = o.effective_risk_score * 2 FROM vulnerability v WHERE v.id = o.vulnerability_id AND v.is_actively_exploited = true AND o.effective_risk_score IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE vulnerability_occurrence o SET effective_risk_score = o.effective_risk_score / 2 FROM vulnerability v WHERE v.id = o.vulnerability_id AND v.is_actively_exploited = true AND o.effective_risk_score IS NOT NULL');
    }
}
