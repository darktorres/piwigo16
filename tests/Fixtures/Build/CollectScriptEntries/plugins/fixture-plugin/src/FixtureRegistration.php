<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Build\CollectScriptEntries\FixturePlugin;

use Piwigo\Asset\AssetContribution;

/**
 * Fixture-only, never autoloaded/instantiated by the real application --
 * exercises `collectScriptEntries()`'s plugin-`src/`-scanning branch
 * (`build/collectScriptEntries.ts`, P29.6) via
 * `collectScriptEntries.test.ts`'s own `roots` override. Real class/import
 * so PHPStan (which scans the whole repo, tests/Fixtures included) analyses
 * it cleanly.
 */
final class FixtureRegistration
{
    /**
     * @return list<AssetContribution>
     */
    // Never called -- this file exists only so its raw text is grep-matched
    // by build/collectScriptEntries.ts, not to be real, invoked PHP.
    // @phpstan-ignore shipmonk.deadMethod
    public function assets(): array
    {
        return [
            AssetContribution::script('fixture-plugin-entry', 'plugins/fixture-plugin/src/fixture-plugin.ts'),
        ];
    }
}
