<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\NotificationByMailSubController;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\TimingHelper;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Mail\NotificationByMailSender;

/**
 * NotificationByMailSubController::doTimeoutTreatment()'s own
 * `$treated_count !== 0` branch (real ceil()-based time estimate,
 * ~line 387) -- distinct from every Browser-suite repost test
 * (NotificationByMailSubControllerTest.php), which all force an
 * IMMEDIATE (0-threshold) sendmail timeout so the per-user loop breaks
 * before treating anyone, always landing on the `$treated_count === 0`
 * branch instead (see that file's own docblock: "the timing race needed
 * to land mid-batch ... isn't reachable deterministically over real
 * HTTP"). Reflection lets this reach the same conclusion the sender's own
 * Integration suite already established for its methods, but for this
 * controller's private static method -- constructing NotificationByMailSender
 * via newInstanceWithoutConstructor() and hand-setting its private
 * startTime/isSendmailTimeout properties needs no DB/mail/template
 * dependency at all, since doTimeoutTreatment() only ever calls
 * $nbmSender->isSendmailTimeout()/->startTime() (both plain property
 * readers), matching ActionControllerTest.php's own "reflection reaches a
 * branch a full HTTP round trip structurally cannot" precedent.
 *
 * The 2 `(float)` casts on $post_count/$treated_count (both plain ints
 * from count()) in that same ceil() expression are confirmed-equivalent
 * mutants: the expression's own left operand (a getMoment() subtraction)
 * is already a float, and PHP auto-promotes an int operand to float in
 * any float-mixed arithmetic regardless of an explicit cast -- removing
 * either cast changes nothing about the resulting value.
 */
function nbmSubReflectSender(float $startTime, bool $isSendmailTimeout): NotificationByMailSender
{
    $sender = new ReflectionClass(NotificationByMailSender::class)->newInstanceWithoutConstructor();

    $startTimeProp = new ReflectionProperty(NotificationByMailSender::class, 'startTime');
    $startTimeProp->setValue($sender, $startTime);

    $isTimeoutProp = new ReflectionProperty(NotificationByMailSender::class, 'isSendmailTimeout');
    $isTimeoutProp->setValue($sender, $isSendmailTimeout);

    return $sender;
}

/**
 * doTimeoutTreatment() reads only $this->pageState/$this->translator
 * (singleton/service-locator elimination campaign, Phase 12 sub-phase
 * 12D) -- a reflected, no-constructor instance with just those 2
 * properties hand-set needs no DB/mail/template dependency either,
 * matching nbmSubReflectSender()'s own precedent just above. Both are
 * set to the real PageStateTestFactory::get()/TranslatorTestFactory::get() pre-boot
 * fallback instances (this file never calls Kernel::boot()) so the
 * tests' own PageStateTestFactory::get()->errors/Translator-driven message
 * assertions below keep reading back the same shared state
 * doTimeoutTreatment() itself just wrote to.
 */
function nbmSubReflectController(): NotificationByMailSubController
{
    $controller = new ReflectionClass(NotificationByMailSubController::class)->newInstanceWithoutConstructor();

    $pageStateProp = new ReflectionProperty(NotificationByMailSubController::class, 'pageState');
    $pageStateProp->setValue($controller, PageStateTestFactory::get());

    $translatorProp = new ReflectionProperty(NotificationByMailSubController::class, 'translator');
    $translatorProp->setValue($controller, TranslatorTestFactory::get());

    return $controller;
}

/**
 * @param array<int|string, mixed> $post
 * @param list<string> $checkKeyTreated
 */
function nbmSubCallDoTimeoutTreatment(NotificationByMailSender $sender, string $postKeyname, array &$post, array $checkKeyTreated): bool
{
    // Real bug, found live: doTimeoutTreatment()'s own $post parameter is
    // by-ref (array &$post), but ReflectionMethod::invoke() does NOT
    // propagate by-ref mutations back to the caller -- it silently passes
    // arguments by value, so $post here stayed untouched no matter what
    // the real method did internally. invokeArgs() with an explicit &$post
    // reference *inside* the args array is the one Reflection call shape
    // that actually preserves the reference (confirmed live).
    $method = new ReflectionMethod(NotificationByMailSubController::class, 'doTimeoutTreatment');

    /** @var bool */
    return $method->invokeArgs(nbmSubReflectController(), [$sender, $postKeyname, &$post, $checkKeyTreated]);
}

test('doTimeoutTreatment computes a real, positive estimated-time when some (but not all) users were already treated', function (): void {
    // A synthetic startTime ~5s in the past makes
    // `TimingHelper::getMoment() - $nbmSender->startTime()` a real,
    // positive, near-deterministic elapsed value -- unlike the Browser
    // suite's forced-0-threshold tests, whose `$treated_count` is always
    // 0 (the loop breaks before treating anyone), this drives the
    // `$treated_count !== 0` branch's own `ceil(...)` computation for
    // real.
    $sender = nbmSubReflectSender(TimingHelper::getMoment() - 5.0, true);

    $post = ['cat_true' => ['ct_treated_1', 'ct_treated_2', 'ct_untreated']];
    $errorCountBefore = count(PageStateTestFactory::get()->errors);

    $result = nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, ['ct_treated_1', 'ct_treated_2']);

    expect($result)->toBeTrue();
    // array_diff() drops the 2 already-treated keys, leaving only the
    // untreated one for a real repost.
    assert(is_array($post['cat_true']));
    expect(array_values($post['cat_true']))->toBe(['ct_untreated']);

    $errors = PageStateTestFactory::get()->errors;
    expect(count($errors))->toBe($errorCountBefore + 1);
    $message = $errors[count($errors) - 1];
    // English plural, untranslated (no admin.lang loaded in this Unit
    // suite) source wording -- "[Estimated time: %d seconds]" with a
    // real, positive digit substituted (not the "0 seconds" every
    // Browser-suite repost test's own forced-immediate-timeout produces).
    expect($message)->toMatch('/\[Estimated time: (\d+) seconds\]\.$/');
    if (preg_match('/\[Estimated time: (\d+) seconds\]\.$/', $message, $matches) !== 1) {
        throw new RuntimeException('could not extract the estimated-time digit from: ' . $message);
    }
    expect((int) $matches[1])->toBeGreaterThan(0);
});

test('doTimeoutTreatment computes the exact ceil()\'d estimated-time, not floor() or round()', function (): void {
    // The test above only asserts > 0, which can't tell ceil() apart
    // from floor()/round(), the elapsed-time subtraction apart from a
    // mutated addition, or the post_count/treated_count division apart
    // from a mutated multiplication. Pinning startTime exactly 10s
    // before a `getMoment()` read taken immediately beforehand makes the
    // real elapsed time deterministically *slightly more* than 10.0 (a
    // few microseconds of real execution time, confirmed live) --
    // ceil() of that is reliably 11, never 10, regardless of the exact
    // jitter. A 1:1 post_count:treated_count ratio isolates that
    // division from affecting the expected value at all.
    $before = TimingHelper::getMoment();
    $sender = nbmSubReflectSender($before - 10.0, true);

    $post = ['cat_true' => ['t1', 't2']];
    $errorCountBefore = count(PageStateTestFactory::get()->errors);

    nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, ['t1', 't2']);

    $errors = PageStateTestFactory::get()->errors;
    $message = $errors[count($errors) - 1];
    expect($message)->toEndWith('[Estimated time: 11 seconds].');
    expect(count($errors))->toBe($errorCountBefore + 1);
});

test('doTimeoutTreatment leaves the estimated-time at exactly 0 when nobody has been treated yet', function (): void {
    // Every other test in this file (Unit and Browser alike) either has
    // 0 treated (the Browser suite's own forced-immediate-timeout) or
    // some-but-not-all treated -- none pins treated_count to *exactly*
    // 0 while also asserting the resulting value is exactly 0, which is
    // what's needed to tell the real `!== 0` guard apart from a mutated
    // `!== 1`/`!== -1` (either of which would wrongly divide by the real
    // treated_count of 0 for this exact input).
    $sender = nbmSubReflectSender(TimingHelper::getMoment() - 5.0, true);

    $post = ['cat_true' => ['t1']];

    nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, []);

    $errors = PageStateTestFactory::get()->errors;
    $message = $errors[count($errors) - 1];
    expect($message)->toEndWith('[Estimated time: 0 seconds].');
});

test('doTimeoutTreatment does nothing when the post key is set but not an array', function (): void {
    // Every other test's $post[$post_keyname] is a real array (isset()
    // and is_array() both true), which can't tell the real `and` apart
    // from a mutated `or` -- a set-but-non-array value distinguishes
    // them (isset() true, is_array() false).
    $sender = nbmSubReflectSender(TimingHelper::getMoment() - 5.0, true);
    $post = ['cat_true' => 'not-an-array'];
    $errorCountBefore = count(PageStateTestFactory::get()->errors);

    $result = nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, ['t1']);

    expect($result)->toBeFalse();
    expect(count(PageStateTestFactory::get()->errors))->toBe($errorCountBefore);
});

test('doTimeoutTreatment drops non-string entries from the reposted selection', function (): void {
    // The existing "computes a real... estimated-time" test's own post
    // array is all-string, so array_filter(..., is_string(...)) never
    // actually excludes anything -- can't tell it apart from a mutated
    // unwrapped version that skips the filter entirely.
    $sender = nbmSubReflectSender(TimingHelper::getMoment() - 5.0, true);
    $post = ['cat_true' => ['t1', 42, 't2']];

    nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, []);

    assert(is_array($post['cat_true']));
    expect(array_values($post['cat_true']))->toBe(['t1', 't2']);
});
