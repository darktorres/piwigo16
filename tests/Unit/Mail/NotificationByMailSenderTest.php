<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Mail\NotificationByMailSender;

/**
 * Piwigo\Mail\NotificationByMailSender's constructor computes
 * $sendmailTimeout from a real `ini_get('max_execution_time')` read
 * times `CurrentConfig::nbmMaxTreatmentTimeoutPercent()`, falling back
 * to `CurrentConfig::nbmTreatmentTimeoutDefault()` whenever that product
 * is `<= 0`. Confirmed via `php -r "var_dump(ini_get('max_execution_time'));"`
 * (also documented on the Integration suite's own
 * `NotificationByMailSenderTest::senderWithImmediateTimeout()` docblock):
 * this CLI SAPI always reads back `'0'`, so every test below drives the
 * inputs explicitly with `ini_set()` rather than relying on ambient
 * state.
 *
 * Resolved through the real DI container (`PresentationAccessor`), same
 * `Kernel::boot()`-with-a-real-`Paths` pattern as
 * `PresentationAccessorTest`'s own docblock -- no dependency here needs a
 * live DB query to merely be *constructed* (only `beginUsersEnv()`/
 * `sendMailNotifications()`/etc. -- out of scope for this file -- ever
 * touch the DB), confirmed by that same Unit-suite test already doing
 * exactly this construction without a real DB connection.
 *
 * $sendmailTimeout has no public getter (`startTime()` is the only public
 * accessor for a constructor-computed field here), so it's read back via
 * Reflection, matching CacheFactoryTest's/ErrorCollectorTest's own
 * established one-liner pattern (no `->setAccessible()` -- a no-op since
 * PHP 8.1).
 */
function nbm_sender_with_timeout_inputs(string $maxExecutionTime, float $percent, int $timeoutDefault): NotificationByMailSender
{
    ini_set('max_execution_time', $maxExecutionTime);
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    // Must be resolved (and written to) *after* boot -- PresentationAccessor::
    // notificationByMailSender() constructor-injects the container-shared
    // CurrentConfig instance, which only exists once Kernel::boot() has built
    // the container; the pre-boot memoized fallback (CurrentConfig::current()
    // before boot) is a distinct object never seen by the sender under
    // construction below.
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->setNbmMaxTreatmentTimeoutPercent($percent);
    $currentConfig->setNbmTreatmentTimeoutDefault($timeoutDefault);

    return PresentationAccessor::notificationByMailSender();
}

function nbm_sendmail_timeout(NotificationByMailSender $sender): float
{
    $value = new ReflectionProperty($sender, 'sendmailTimeout')->getValue($sender);
    Assert::assertIsFloat($value);

    return $value;
}

afterEach(function (): void {
    Kernel::reset();
    CurrentConfig::current()->reset();
    ini_set('max_execution_time', '0');
});

test('sendmailTimeout falls back to the configured default exactly at the <= 0 boundary', function (): void {
    // Kills line 106's DecrementInteger (`<= -1`), IfNegated (`> 0`),
    // SmallerOrEqualToGreater (`> 0`), and SmallerOrEqualToSmaller
    // (`< 0`) all at once: a computed product of exactly 0.0 makes the
    // real `<= 0` condition true (falls back to the configured default),
    // while all four mutants evaluate `0.0` against their mutated
    // operator/operand as false (keep the computed 0.0 instead) -- see
    // this file's own docblock for why max_execution_time='0' (intval 0)
    // deterministically produces a computed value of exactly 0.0
    // regardless of the percent operand.
    $sender = nbm_sender_with_timeout_inputs(maxExecutionTime: '0', percent: 0.8, timeoutDefault: 55);

    expect(nbm_sendmail_timeout($sender))->toBe(55.0);
});

test('sendmailTimeout keeps the real computed product, unmodified, just above the fallback boundary', function (): void {
    // Kills line 106's IncrementInteger (`<= 1`): a computed product of
    // 0.5 leaves the real `<= 0` condition false (keeps 0.5), while the
    // mutant's `<= 1` is true for 0.5 (falls back to the configured
    // default instead) -- distinguishable only by picking a strictly
    // positive value below 1, unlike the exactly-0.0 test above.
    //
    // Also kills line 104's MultiplicationToDivision: `1 * 0.5` (0.5,
    // this test's own expectation) vs the mutant's `1 / 0.5` (2.0) --
    // both stay above the `<= 0` boundary either way, so this is a clean
    // kill independent of the boundary tested above.
    $sender = nbm_sender_with_timeout_inputs(maxExecutionTime: '1', percent: 0.5, timeoutDefault: 55);

    expect(nbm_sendmail_timeout($sender))->toBe(0.5);
});

/**
 * Confirmed-equivalent: line 104's RemoveDoubleCast (dropping the
 * `(float)` cast around `intval(ini_get('max_execution_time'))`, leaving
 * a bare `intval(...) * $nbmMaxTreatmentTimeoutPercent`). PHP's own
 * arithmetic-promotion rule already forces `int * float` to `float`
 * unconditionally -- $nbmMaxTreatmentTimeoutPercent is statically typed
 * `float` (CurrentConfig::nbmMaxTreatmentTimeoutPercent()'s own return
 * type) -- so the explicit cast can never change either the resulting
 * value or its type, for any intval() result. Confirmed live: both tests
 * above (the exact-0.0 boundary case and the 0.5 fractional case) pass
 * identically with this cast removed.
 */
