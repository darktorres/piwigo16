<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/**
 * Guards the SCHEMA <-> generated-accessors contract on Piwigo\Config\Config.
 *
 * Three invariants:
 *   1. Running tools/build-config-accessors.php produces no diff (file in sync).
 *   2. Every SCHEMA entry's `method` actually exists as a public static method.
 *   3. Every public static method that looks like a typed accessor (no
 *      parameters, return type other than self) has a SCHEMA entry, OR is on
 *      the explicit allow-list of preamble/bulk/derived methods.
 */
final class SchemaIntegrityTest extends TestCase
{
    private const ALLOW_LIST = [
        // Preamble / framework
        'instance', 'attachGlobals',
        // Bulk reader
        'all',
        // Test helper
        'reset',
        // Derived accessors (no SCHEMA key — call other accessors)
        'flipPictureExt', 'flipFileExt',
    ];

    public function test_generator_is_in_sync(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $process  = new Process(['php', 'tools/build-config-accessors.php', '--check'], $repoRoot);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            "Config.php is out of sync with SCHEMA — re-run `php tools/build-config-accessors.php`.\n"
            . 'stdout: ' . $process->getOutput() . "\n"
            . 'stderr: ' . $process->getErrorOutput()
        );
    }

    public function test_every_schema_method_exists(): void
    {
        foreach (Config::SCHEMA as $key => $entry) {
            self::assertTrue(
                method_exists(Config::class, $entry['method']),
                "SCHEMA entry '$key' references method Config::{$entry['method']}() which does not exist."
            );
        }
    }

    public function test_every_zero_arg_accessor_has_schema_entry(): void
    {
        $methodToKey = [];
        foreach (Config::SCHEMA as $key => $entry) {
            $methodToKey[$entry['method']] = $key;
        }

        $reflection = new ReflectionClass(Config::class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->isPublic() || $method->getNumberOfParameters() !== 0) {
                continue; // skip non-public methods + multi-param helpers (raw/has/delete/override/loadArray)
            }
            $name = $method->getName();
            if (in_array($name, self::ALLOW_LIST, true)) {
                continue;
            }
            self::assertArrayHasKey(
                $name,
                $methodToKey,
                "Public static accessor Config::$name() has no SCHEMA entry. Either add one, or add '$name' to SchemaIntegrityTest::ALLOW_LIST if it's a framework/derived method."
            );
        }
    }
}
