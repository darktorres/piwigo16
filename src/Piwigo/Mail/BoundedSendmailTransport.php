<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Drop-in replacement for Symfony Mailer's own SendmailTransport (which
 * `Transport::fromDsn('native://default')` resolves to whenever
 * `sendmail_path` is configured, i.e. whenever MailService::mail() isn't
 * using an explicit SMTP host). That upstream transport shells out via a
 * raw proc_open() (Symfony\Component\Mailer\Transport\Smtp\Stream\
 * ProcessStream) with no timeout mechanism of any kind -- a real,
 * confirmed bug: a slow/unreachable local MTA (trying to actually deliver
 * over the network rather than failing fast) blocks fclose()/proc_close()
 * indefinitely, hanging the whole synchronous HTTP request (observed
 * >2 minutes before being killed in RegisterControllerTest.php, e.g. for
 * the default-checked "send password by mail" registration option).
 *
 * Uses Symfony\Component\Process\Process instead, the same
 * setTimeout()-bounded subprocess mechanism already used by
 * BackupService/UserListCommand in this codebase -- Process enforces its
 * timeout via non-blocking stream_select() polling, not pcntl signals, so
 * it works under mod_php/php-fpm as well as CLI (pcntl's own signal
 * handling is CLI-only-reliable and unavailable in this project's real
 * Apache serving path).
 *
 * Recipient/sender/dot-stuffing handling mirrors SendmailTransport::
 * doSend() exactly (that logic itself isn't the buggy part -- only the
 * unbounded process wait is).
 */
final class BoundedSendmailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $sendmailPath,
        private readonly float $timeoutSeconds,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    #[\Override]
    public function __toString(): string
    {
        return 'native://default (bounded)';
    }

    #[\Override]
    protected function doSend(SentMessage $message): void
    {
        $binaryAndFlags = preg_split('/\s+/', trim($this->sendmailPath));
        if ($binaryAndFlags === false || $binaryAndFlags === [] || $binaryAndFlags[0] === '') {
            throw new TransportException('BoundedSendmailTransport: sendmail_path is empty or invalid: "' . $this->sendmailPath . '"');
        }

        $envelope = $message->getEnvelope();
        $recipients = $envelope->getRecipients();

        $flags = array_slice($binaryAndFlags, 1);
        if ($recipients !== []) {
            // Explicit envelope recipients are always available and more
            // correct than letting sendmail re-derive them from the
            // To/Cc/Bcc headers (-t) -- same reasoning as upstream
            // SendmailTransport::doSend().
            $flags = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== '-t'));
        }

        $dotStuffingNeeded = ! in_array('-i', $flags, true) && ! in_array('-oi', $flags, true);

        $command = [$binaryAndFlags[0], ...$flags];
        if (! in_array('-f', $flags, true)) {
            $command[] = '-f';
            $command[] = $envelope->getSender()->getEncodedAddress();
        }
        if ($recipients !== []) {
            $command[] = '--';
            foreach ($recipients as $recipient) {
                $command[] = $recipient->getEncodedAddress();
            }
        }

        $body = str_replace("\r\n", "\n", $message->toString());
        if ($dotStuffingNeeded) {
            $body = str_replace("\n.", "\n..", $body);
        }

        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);
        $process->setInput($body);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new TransportException('Sendmail process timed out after ' . $this->timeoutSeconds . 's: ' . $e->getMessage(), 0, $e);
        } catch (ProcessExceptionInterface $e) {
            // Normalized to TransportExceptionInterface -- MailService::
            // mail()'s own catch(TransportExceptionInterface) block is the
            // single place this project handles a failed send (logs a
            // warning, returns false), and every other Mailer transport
            // already only ever throws that interface.
            throw new TransportException('Sendmail process could not be started: ' . $e->getMessage(), 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new TransportException('Sendmail process failed with exit code ' . $process->getExitCode() . ': ' . $process->getErrorOutput());
        }
    }
}
