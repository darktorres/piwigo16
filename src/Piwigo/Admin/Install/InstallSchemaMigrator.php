<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs the real Doctrine Migrations baseline (src/Piwigo/Migrations/)
 * against an already-seeded install-time Connection -- extracted out of
 * InstallWizard::performInstall()'s own former step-2 block.
 */
final class InstallSchemaMigrator
{
    /**
     * tables creation, driven by the real Doctrine Migrations baseline
     * (src/Piwigo/Migrations/) instead of a static SQL file -- see
     * InstallWizard's own constructor docblock for why the
     * DependencyFactory below is built directly from this already-seeded
     * $conn rather than resolved via the container (config/container.php's
     * own DependencyFactory::class entry backs bin/piwigo
     * migrations:migrate's CLI usage only). Runs the real MigrateCommand
     * programmatically (ArrayInput/setInteractive(false) skips its
     * confirmation prompt, matching --no-interaction) rather than calling
     * AliasResolver::resolveVersionAlias()/Migrator::migrate()/
     * MigratorConfiguration directly -- all 3 are internal Doctrine APIs
     * (method.internalInterface/new.internalClass), off limits from
     * outside the Doctrine root namespace; MigrateCommand itself is the
     * sanctioned public entry point, and running it this way also means a
     * future point release adding a new migration file here becomes the
     * real upgrade path for an existing install (bin/piwigo
     * migrations:migrate), not just a fresh-install mechanism.
     *
     * @return string|null a formatted failure message on failure, or null on success
     */
    public function migrate(Connection $conn): ?string
    {
        $migrationsEm = EntityManagerFactory::build($conn);
        $dependencyFactory = MigrationDependencyFactory::build($migrationsEm);
        $migrateInput = new ArrayInput([
            'version' => 'latest',
            '--allow-no-migration' => true,
        ]);
        $migrateInput->setInteractive(false);
        $migrateOutput = new BufferedOutput();
        // MigrateCommand::run() is called directly (see this method's own
        // docblock above for why), not through a full Symfony Application --
        // unlike a real CLI invocation, that does NOT guarantee every
        // failure is caught internally and converted to a plain exit code.
        // A driver-level exception (mysqli's own exception-throwing mode,
        // e.g. a genuine "table already exists") can still escape run()
        // uncaught. public/install.php's own top-level catch only handles
        // ResponseReadyException, so letting that propagate would reach the
        // client as a raw fatal error/blank page instead of the installer's
        // own error UI -- caught here and folded into the same "exit code"
        // failure path below instead.
        try {
            $migrateExitCode = new MigrateCommand($dependencyFactory)
                ->run($migrateInput, $migrateOutput);
        } catch (Throwable $e) {
            $migrateExitCode = 1;
            $migrateOutput->writeln($e->getMessage());
        }

        if ($migrateExitCode === 0) {
            return null;
        }

        return 'Schema migration failed (migrations:migrate exit code ' . $migrateExitCode . '): ' . $migrateOutput->fetch();
    }
}
