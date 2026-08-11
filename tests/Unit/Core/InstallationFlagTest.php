<?php

declare(strict_types=1);

use Piwigo\Core\InstallationFlag;

/**
 * Piwigo\Core\InstallationFlag -- had no dedicated coverage of its own
 * (only exercised indirectly via LangTest.php's mark()/reset() calls
 * around the InstallationFlag-active default-language path). The
 * PHPWG_INSTALLED-constant branch of isActive() is deliberately not
 * exercised here for the same reason FilterState/PageState-style
 * "constant can't be undefined once set" tests avoid it elsewhere in
 * this suite: defining it would permanently leak into every later test
 * sharing this same process.
 */
test('isActive is false before mark() has ever been called', function (): void {
    expect(new InstallationFlag()->isActive())
        ->toBeFalse();
});

test('mark() makes isActive true', function (): void {
    $flag = new InstallationFlag();
    $flag->mark();

    expect($flag->isActive())
        ->toBeTrue();
});

test('reset() clears the marked flag back to false, not just leaving it unset', function (): void {
    // Kills the FalseToTrue mutation on reset()'s own $this->marked =
    // false: confirmed live via a sed-applied mutation that without
    // this exact assignment, isActive() stays true after reset().
    $flag = new InstallationFlag();
    $flag->mark();
    expect($flag->isActive())
        ->toBeTrue();

    $flag->reset();

    expect($flag->isActive())
        ->toBeFalse();
});
