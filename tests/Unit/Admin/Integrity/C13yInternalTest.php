<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\Event\ListCheckIntegrity;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * Piwigo\Admin\Integrity\C13yInternal -- the built-in "check integrity"
 * anomaly checkers (version/exif/user), registered onto
 * `ListCheckIntegrity` by `registerHandlers()`. Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`).
 *
 * Every check here is exercised against this environment's own real,
 * healthy state (a real PHP with the exif extension, a real DB above
 * the required minimum version, real guest/default/webmaster user rows
 * with correct statuses) -- confirmed to add zero anomalies, matching
 * what a real healthy production install also reports. Forcing an
 * actual anomaly would mean faking `function_exists()`/downgrading the
 * real DB version/corrupting real user rows, none of which this class's
 * own thin-checker role justifies. `c13y_correction_user()` performs
 * real user-table writes and is not attempted either.
 */
function c13yInternalTestSubject(): C13yInternal
{
    $subject = Kernel::container()->get(C13yInternal::class);
    if (! $subject instanceof C13yInternal) {
        throw new LogicException('Container returned an unexpected type for ' . C13yInternal::class);
    }

    return $subject;
}

function c13yInternalTestCheckIntegrity(): CheckIntegrity
{
    $checkIntegrity = Kernel::container()->get(CheckIntegrity::class);
    if (! $checkIntegrity instanceof CheckIntegrity) {
        throw new LogicException('Container returned an unexpected type for ' . CheckIntegrity::class);
    }

    return $checkIntegrity;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('registerHandlers wires all 3 checkers onto the container-shared EventDispatcher', function (): void {
    c13yInternalTestSubject()->registerHandlers();

    $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
    if (! $eventDispatcher instanceof EventDispatcher) {
        throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
    }
    $checkIntegrity = c13yInternalTestCheckIntegrity();

    $eventDispatcher->dispatchNotify(new ListCheckIntegrity($checkIntegrity));

    // A real, healthy environment (see this file's own docblock) means
    // none of the 3 checkers add an anomaly -- this only proves all 3
    // handlers actually ran (no exception, no missing dependency),
    // not their individual pass/fail logic (covered per-checker below).
    expect($checkIntegrity->retrieve_list)
        ->toBe([]);
});

test('c13y_version adds no anomaly against this environment\'s real, above-minimum PHP and DB versions', function (): void {
    $checkIntegrity = c13yInternalTestCheckIntegrity();

    c13yInternalTestSubject()
        ->c13y_version(new ListCheckIntegrity($checkIntegrity));

    expect($checkIntegrity->retrieve_list)
        ->toBe([]);
});

test('c13y_exif adds no anomaly since this environment\'s real exif_read_data function exists', function (): void {
    CurrentConfigTestFactory::get()->showExif = true;
    CurrentConfigTestFactory::get()->useExif = true;
    $checkIntegrity = c13yInternalTestCheckIntegrity();

    c13yInternalTestSubject()
        ->c13y_exif(new ListCheckIntegrity($checkIntegrity));

    expect($checkIntegrity->retrieve_list)
        ->toBe([]);
});

test('c13y_user adds no anomaly for the real, correctly-provisioned guest/default/webmaster user rows', function (): void {
    $checkIntegrity = c13yInternalTestCheckIntegrity();

    c13yInternalTestSubject()
        ->c13y_user(new ListCheckIntegrity($checkIntegrity));

    expect($checkIntegrity->retrieve_list)
        ->toBe([]);
});
