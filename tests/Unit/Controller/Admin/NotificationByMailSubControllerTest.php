<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\NotificationByMailSubController;
use Piwigo\Core\PageState;
use Piwigo\Core\TimingHelper;
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
    return $method->invokeArgs(null, [$sender, $postKeyname, &$post, $checkKeyTreated]);
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
    $errorCountBefore = count(PageState::current()->errors);

    $result = nbmSubCallDoTimeoutTreatment($sender, 'cat_true', $post, ['ct_treated_1', 'ct_treated_2']);

    expect($result)->toBeTrue();
    // array_diff() drops the 2 already-treated keys, leaving only the
    // untreated one for a real repost.
    assert(is_array($post['cat_true']));
    expect(array_values($post['cat_true']))->toBe(['ct_untreated']);

    $errors = PageState::current()->errors;
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
