<?php

declare(strict_types=1);

use Piwigo\Bootstrap\PresentationAccessor;
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
 * the happy path is exercised elsewhere (Ws\PwgExtensions.php/
 * Ws\PwgImages.php's own real usage, per this class's own docblock).
 */
afterEach(function (): void {
    \Piwigo\Core\Kernel::reset();
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
