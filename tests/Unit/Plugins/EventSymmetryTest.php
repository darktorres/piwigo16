<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;

/**
 * Validates event hook symmetry in core code.
 *
 * Rule: every event name passed to EventDispatcher::addListener() anywhere in
 * src/ or include/ must appear at least once as the first argument of
 * EventDispatcher::dispatch() or EventDispatcher::notify() somewhere in the full codebase
 * (src/, include/, plugins/).
 *
 * This catches handlers registered for events that no longer exist
 * (renamed or deleted trigger call) while accepting plugin-only events
 * as long as a plugin fires them.
 */
final class EventSymmetryTest extends TestCase
{
    private static string $root;

    /** @var array<string, list<string>>  eventName => list of files that add_event_handler it */
    private static array $handlers = [];

    /** @var array<string, true>  eventName => true  (fired anywhere in codebase) */
    private static array $triggers = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 3);

        // Collect add_event_handler registrations from src/ and include/.
        foreach ([self::$root . '/src', self::$root . '/include'] as $dir) {
            self::scanForPattern(
                $dir,
                '/add_event_handler\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                self::$handlers
            );
        }

        // Collect trigger_change / trigger_notify calls from the whole codebase.
        $triggerIndex = [];
        foreach ([self::$root . '/src', self::$root . '/include', self::$root . '/plugins'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            self::scanForPattern(
                $dir,
                '/(?:trigger_change|trigger_notify)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $triggerIndex
            );
        }
        // Flatten to a set.
        foreach (array_keys($triggerIndex) as $name) {
            self::$triggers[$name] = true;
        }
    }

    public function test_every_handler_has_a_trigger(): void
    {
        $orphans = [];
        foreach (self::$handlers as $eventName => $files) {
            if (!isset(self::$triggers[$eventName])) {
                $orphans[$eventName] = array_unique($files);
            }
        }

        $report = [];
        foreach ($orphans as $name => $files) {
            $short = array_map(fn (string $f): string => str_replace(self::$root . '/', '', $f), $files);
            $report[] = "'$name' registered in: " . implode(', ', $short);
        }

        self::assertEmpty(
            $orphans,
            "EventDispatcher::addListener() calls with no matching trigger anywhere in codebase:\n  "
            . implode("\n  ", $report)
        );
    }


    /**
     * @param array<string, list<string>> $index
     */
    private static function scanForPattern(string $dir, string $pattern, array &$index): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!($file instanceof \SplFileInfo) || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname()) ?: '';
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $name) {
                $index[$name][] = $file->getPathname();
            }
        }
    }
}
