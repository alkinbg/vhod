<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class OperationalHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testBackupScriptCreatesConsistentEncryptedArtifactWithoutEmbeddingSecrets(): void
    {
        $path = $this->root.'/ops/backup/create-backup.sh';
        self::assertFileExists($path);
        $script = (string) file_get_contents($path);

        self::assertStringContainsString('set -Eeuo pipefail', $script);
        self::assertStringContainsString('umask 077', $script);
        self::assertStringContainsString('--single-transaction', $script);
        self::assertStringContainsString('var/storage/documents', $script);
        self::assertStringContainsString('gpg', $script);
        self::assertStringContainsString('--symmetric', $script);
        self::assertStringContainsString('--passphrase-fd', $script);
        self::assertStringContainsString('sha256sum', $script);
        self::assertStringNotContainsString('--passphrase ', $script);
    }

    public function testRestoreVerifierRefusesProductionLikeDatabaseAndValidatesApplicationSchema(): void
    {
        $path = $this->root.'/ops/backup/verify-restore.sh';
        self::assertFileExists($path);
        $script = (string) file_get_contents($path);

        self::assertStringContainsString('vhod_restore_test_', $script);
        self::assertStringContainsString('sha256sum -c', $script);
        self::assertStringContainsString('--decrypt', $script);
        self::assertStringContainsString('DROP DATABASE IF EXISTS', $script);
        self::assertStringContainsString('doctrine:schema:validate', $script);
        self::assertStringContainsString('trap', $script);
    }

    public function testNginxTemplateContainsLoginAndGeneralRateLimits(): void
    {
        $path = $this->root.'/ops/nginx/vhod-hardening.conf.example';
        self::assertFileExists($path);
        $config = (string) file_get_contents($path);

        self::assertStringContainsString('limit_req_zone', $config);
        self::assertStringContainsString('$request_method', $config);
        self::assertStringContainsString('rate=5r/m', $config);
        self::assertStringContainsString('limit_req_status 429', $config);
        self::assertStringContainsString('Strict-Transport-Security', $config);
        self::assertStringContainsString('X-Content-Type-Options', $config);
    }

    public function testMonitoringRunbookDefinesExternalAndDataProtectionSignals(): void
    {
        $path = $this->root.'/ops/monitoring.md';
        self::assertFileExists($path);
        $runbook = (string) file_get_contents($path);

        self::assertStringContainsString('/healthz', $runbook);
        self::assertStringContainsString('5xx', $runbook);
        self::assertStringContainsString('MariaDB', $runbook);
        self::assertStringContainsString('backup age', $runbook);
        self::assertStringContainsString('off-host', $runbook);
    }
}
