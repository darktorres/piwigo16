<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\IntroActionRequest;

test('fromArray recognizes the hide_newsletter_subscription action', function (): void {
    $request = IntroActionRequest::fromArray(['action' => 'hide_newsletter_subscription']);

    expect($request->isHideNewsletterSubscription)->toBeTrue();
});

test('fromArray treats a missing action as not hide_newsletter_subscription', function (): void {
    $request = IntroActionRequest::fromArray([]);

    expect($request->isHideNewsletterSubscription)->toBeFalse();
});

test('fromArray treats any other action value as not hide_newsletter_subscription', function (): void {
    $request = IntroActionRequest::fromArray(['action' => 'something_else']);

    expect($request->isHideNewsletterSubscription)->toBeFalse();
});
