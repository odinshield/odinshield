<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * #134 — import redesign (release 2.2.0, NOT backwards compatible despite the minor version):
 *
 *  1. `vendor.aliases` / `software.aliases` (JSON, nullable) — alternative spellings for the
 *     key-based matcher.
 *  2. Drift report: for every `software.cpe`, the CPE's vendor/product tokens are compared with
 *     `vendor.vendor_key` / `software.product_key`. Mismatches are printed as warnings and NOT
 *     corrected automatically — in the dev data the CPE was sometimes the more correct of the two
 *     (`fortinet` vs `fortinet_net`), so which side wins is an operator decision. Afterwards
 *     `odinshield:vulnerability:recheck` surfaces anything missed.
 *  3. `software.cpe` is dropped: the CPE only ever identified the product, which the keys do.
 *  4. The EUVD importer is gone: `vulnerability.euvd_sources` and `euvd_import_state` are dropped.
 *     `vulnerability.source = 'euvd'` stays as a historic label.
 *  5. `exploitation_enrichment_state` — state of the daily FIRST EPSS / CISA KEV pass that
 *     replaces what EUVD relayed.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#134 import redesign: aliases on vendor/software, drop software.cpe (with drift report), remove EUVD, add exploitation enrichment state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor ADD aliases JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE software ADD aliases JSON DEFAULT NULL');

        $this->reportCpeDrift();

        $this->addSql('ALTER TABLE software DROP cpe');
        $this->addSql('ALTER TABLE vulnerability DROP euvd_sources');
        $this->addSql('DROP TABLE IF EXISTS euvd_import_state');

        $this->addSql('CREATE TABLE exploitation_enrichment_state (id VARCHAR(16) NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_message TEXT DEFAULT NULL, consecutive_failures INT DEFAULT 0 NOT NULL, paused BOOLEAN DEFAULT false NOT NULL, last_pause_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_run_updated INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
    }

    /**
     * Runs during `up()` on the live connection — before the column is dropped. The comparison is
     * done in PHP (not SQL) so the token rule matches KeyNormaliser: lowercase, non-alphanumerics
     * collapsed to `_`, trimmed.
     */
    private function reportCpeDrift(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, s.version, s.product_key, s.cpe, v.vendor_key
               FROM software s
               JOIN vendor v ON v.id = s.vendor_id
              WHERE s.cpe IS NOT NULL AND s.cpe <> \'\'
           ORDER BY s.id'
        );

        $drift = 0;
        foreach ($rows as $row) {
            $tokens = $this->cpeTokens((string) $row['cpe']);
            if (null === $tokens) {
                $this->warnIf(true, sprintf('software #%d %s %s: CPE "%s" is not a CPE 2.2/2.3 URI — nothing to compare, the keys stay as they are.', $row['id'], $row['name'], $row['version'], $row['cpe']));
                continue;
            }
            $vendorKey = $this->normalise((string) $row['vendor_key']);
            $productKey = $this->normalise((string) $row['product_key']);
            $mismatch = [];
            if ($tokens['vendor'] !== $vendorKey) {
                $mismatch[] = sprintf('vendor_key "%s" vs CPE vendor "%s"', $row['vendor_key'], $tokens['vendor']);
            }
            if ($tokens['product'] !== $productKey) {
                $mismatch[] = sprintf('product_key "%s" vs CPE product "%s"', $row['product_key'], $tokens['product']);
            }
            if ([] !== $mismatch) {
                ++$drift;
                $this->warnIf(true, sprintf(
                    'software #%d %s %s: %s. The CPE is dropped by this migration; if the CPE spelling is the one advisories use, set it as key or alias by hand.',
                    $row['id'],
                    $row['name'],
                    $row['version'],
                    implode(', ', $mismatch),
                ));
            }
        }

        $this->write(sprintf('#134 CPE drift report: %d of %d CPE-bearing software rows have keys that differ from their CPE tokens.', $drift, count($rows)));
    }

    /**
     * @return array{vendor: string, product: string}|null
     */
    private function cpeTokens(string $cpe): ?array
    {
        $cpe = trim($cpe);
        if (str_starts_with($cpe, 'cpe:2.3:')) {
            $rest = substr($cpe, strlen('cpe:2.3:'));
        } elseif (str_starts_with($cpe, 'cpe:/')) {
            $rest = substr($cpe, strlen('cpe:/'));
        } else {
            return null;
        }
        $parts = preg_split('/(?<!\\\\):/', $rest) ?: [];
        $vendor = $this->normalise((string) preg_replace('/\\\\(.)/', '$1', $parts[1] ?? ''));
        $product = $this->normalise((string) preg_replace('/\\\\(.)/', '$1', $parts[2] ?? ''));
        if ('' === $vendor || '' === $product) {
            return null;
        }

        return ['vendor' => $vendor, 'product' => $product];
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($value))), '_');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('The CPE strings and the EUVD state are dropped and cannot be restored.');
    }
}
