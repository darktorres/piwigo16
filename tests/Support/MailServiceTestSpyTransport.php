<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Override;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A real `Symfony\Component\Mailer\Transport\TransportInterface` that
 * captures every message it's asked to send instead of attempting a real
 * network/sendmail delivery -- same real-spy-transport technique as
 * `Sentry\Transport\TransportInterface`'s own spy precedent
 * (`SentryMiddlewareTest.php`'s `$spyTransport`), applied to
 * `MailService::__construct()`'s own injectable `$transportOverride` seam.
 * Replaces the old `BeforeSendMail` event side-channel:
 * tests needing to isolate `MailService::mail()` from a real send now wire
 * this in at construction time instead of registering a plugin-event
 * handler for a mechanism no plugin ever legitimately used.
 */
final class MailServiceTestSpyTransport implements TransportInterface
{
    /**
     * @var list<Email>
     */
    public array $sent = [];

    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }

        return null;
    }

    #[Override]
    public function __toString(): string
    {
        return 'spy://mail-service-test';
    }
}
