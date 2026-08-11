<?php

declare(strict_types=1);

use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Url\UrlService;

/**
 * Piwigo\Bootstrap\PresentationAccessor -- same "unexpected type" gap
 * shape as AdminAccessorTest.php/CoreDomainAccessorTest.php: all 5
 * accessors' own \LogicException guards had zero coverage even though
 * the happy path is exercised elsewhere (Ws\Extensions.php/
 * Ws\Images.php's own real usage, per this class's own docblock).
 * Also true of the `instanceof` check itself -- the wrong-type tests
 * below only prove the throw side; nothing proved a real, correctly
 * -typed instance is let through. Same Kernel::boot()-with-a-real-Paths
 * pattern as AdminAccessorTest.php's own docblock -- none of these 5
 * services need CurrentTemplate/CurrentConfig seeded too, unlike the
 * template-dependent renderers in that other file.
 */
afterEach(function (): void {
    Kernel::reset();
});

test('every accessor returns its real, correctly-typed instance from a real container, without throwing', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // Each accessor's own return type already guarantees the resolved
    // instance's class -- a mismatch would throw a TypeError before this
    // array literal could even finish building, so an explicit
    // toBeInstanceOf() per accessor would just be re-asserting what PHP
    // itself already enforces. What's actually under test is that every
    // one of the 5 calls below completes without hitting its internal
    // "Container returned an unexpected type" guard (see this test's own
    // docblock); an uncaught LogicException from any of them fails the
    // test on its own.
    $instances = [
        PresentationAccessor::htmlService(),
        PresentationAccessor::mailService(),
        PresentationAccessor::urlService(),
        PresentationAccessor::notificationByMailSender(),
        PresentationAccessor::pictureRateRenderer(),
    ];

    expect($instances)
        ->toHaveCount(5);
});

test('htmlService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        HtmlService::class,
        static fn () => PresentationAccessor::htmlService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . HtmlService::class);

test('mailService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MailService::class,
        static fn () => PresentationAccessor::mailService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MailService::class);

test('urlService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UrlService::class,
        static fn () => PresentationAccessor::urlService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UrlService::class);

test('notificationByMailSender throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        NotificationByMailSender::class,
        static fn () => PresentationAccessor::notificationByMailSender()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . NotificationByMailSender::class);

test('pictureRateRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PictureRateRenderer::class,
        static fn () => PresentationAccessor::pictureRateRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PictureRateRenderer::class);
