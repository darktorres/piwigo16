<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Backup\BackupService;
use Piwigo\Command\BackupCreateCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tests/Unit/Command/BackupCreateCommandTest.php already covers the
 * failure branch (a broken DB connection, forced via a closed local
 * port -- no need for a real backup pipeline run) and its own docblock
 * explicitly defers the real success path to Integration tier. This is
 * that companion: a genuine BackupService::create() call through the
 * command (mysqldump + tar), same "real archive, then unlink() in
 * tearDown" discipline as tests/Integration/BackupServiceTest.php, which
 * already does this several times over for BackupService directly.
 */
final class BackupCreateCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $archivePath = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->archivePath !== '' && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }
        parent::tearDown();
    }

    public function test_execute_reports_success_and_the_real_archive_path(): void
    {
        $command = new BackupCreateCommand(new BackupService());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        $matched = preg_match('#Backup created: (.+\.tar\.gz)\R?$#', $display, $matches);
        self::assertSame(1, $matched, $display);
        $this->archivePath = $matches[1];

        self::assertFileExists($this->archivePath);
        self::assertStringStartsWith(dirname(__DIR__, 2) . '/_data/backups/piwigo-backup-', $this->archivePath);
    }
}
