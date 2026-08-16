<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Mail\MailService;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Rebuilds an already-constructed `MailService` with its `$transportOverride`
 * swapped for the given transport, reading every other constructor-promoted
 * (`private readonly`) collaborator back off the original instance via
 * Reflection. Lets every existing `mail_service_test_build()`-style
 * caller across the test suite keep building a `MailService` exactly as
 * it always has -- only the handful of send-capturing helpers that
 * actually need a `MailServiceTestSpyTransport` reach for this, so
 * real-transport tests (the fake-SMTP-server ones, `buildMailer()`'s own
 * DSN-branch test) are unaffected by construction order or which builder
 * function a given test happens to call.
 */
final class MailServiceTestTransportSwap
{
    public static function with(MailService $service, TransportInterface $transport): MailService
    {
        $reflClass = new ReflectionClass(MailService::class);
        $ctor = $reflClass->getConstructor();
        if (! $ctor instanceof ReflectionMethod) {
            throw new LogicException('MailService has no constructor');
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $args[$name] = $name === 'transportOverride' ? $transport : $reflClass->getProperty($name)->getValue($service);
        }

        return $reflClass->newInstanceArgs($args);
    }
}
