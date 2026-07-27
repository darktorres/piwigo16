<?php

declare(strict_types=1);

use Piwigo\Mail\BoundedSendmailTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * Real subprocess-based tests (no mocking of Process/Symfony Mailer) --
 * each test substitutes a small, disposable executable script for the real
 * `/usr/sbin/sendmail` binary via BoundedSendmailTransport's own
 * $sendmailPath constructor argument, matching exactly how it's really
 * configured (php.ini's sendmail_path, "<binary> <flags...>").
 */
function fakeSendmailScript(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'pwg_fake_sendmail_') . '.sh';
    file_put_contents($path, "#!/usr/bin/env bash\n" . $body);
    chmod($path, 0755);

    return $path;
}

/**
 * @return array{0: string, 1: string, 2: string} script path, argv-capture file, stdin-capture file
 */
function fakeSendmailCapturing(int $exitCode = 0): array
{
    $argvFile = tempnam(sys_get_temp_dir(), 'pwg_argv_');
    $stdinFile = tempnam(sys_get_temp_dir(), 'pwg_stdin_');
    $script = fakeSendmailScript(<<<BASH
        printf '%s\\n' "\$@" > '{$argvFile}'
        cat > '{$stdinFile}'
        exit {$exitCode}
        BASH);

    return [$script, $argvFile, $stdinFile];
}

function fakeSendmailSleeping(float $seconds): string
{
    return fakeSendmailScript("cat > /dev/null\nsleep {$seconds}\n");
}

function testEmail(string $to = 'recipient@example.test', string $subject = 'Test'): Email
{
    return new Email()
        ->from('sender@example.test')
        ->to($to)
        ->subject($subject)
        ->text("line one\nline two");
}

/**
 * @param list<string> $paths
 */
function cleanupFakeSendmail(array $paths): void
{
    foreach ($paths as $path) {
        @unlink($path);
    }
}

test('doSend invokes sendmail with -f<sender>, recipients after --, and strips -t', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(testEmail('bob@example.test'));

    $argv = trim((string) file_get_contents($argvFile));
    $lines = explode("\n", $argv);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($lines)->not->toContain('-t')
        ->and($lines)->toContain('-i')
        ->and($lines)->toContain('-f')
        ->and($lines)->toContain('sender@example.test')
        ->and($lines)->toContain('--')
        ->and($lines)->toContain('bob@example.test');
});

test('doSend pipes the full MIME message (headers + body) to sendmail\'s stdin', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(testEmail(subject: 'Hello Sendmail'));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain('Subject: Hello Sendmail')
        ->and($stdin)->toContain('line one')
        ->and($stdin)->toContain('line two');
});

test('doSend doubles a leading dot on its own line when -i/-oi is absent (dot-stuffing)', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    // No -i/-oi in the configured flags this time.
    $transport = new BoundedSendmailTransport($script . ' -t', 5.0);
    $transport->send(new Email()
        ->from('sender@example.test')
        ->to('bob@example.test')
        ->subject('Dot test')
        ->text("before\n.\nafter"));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain("before\n..\nafter");
});

test('doSend leaves a leading dot alone when -i is present (no dot-stuffing)', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(new Email()
        ->from('sender@example.test')
        ->to('bob@example.test')
        ->subject('Dot test')
        ->text("before\n.\nafter"));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain("before\n.\nafter")
        ->and($stdin)->not->toContain("before\n..\nafter");
});

test('doSend throws a TransportException (not an unbounded hang) when sendmail exceeds the configured timeout', function (): void {
    $script = fakeSendmailSleeping(5.0);
    $transport = new BoundedSendmailTransport($script, 0.3);

    $start = hrtime(true);
    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'timed out');
    $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

    cleanupFakeSendmail([$script]);

    // The whole point of this class: bounded by ~$timeoutSeconds, never
    // anywhere near the fake sendmail's real 5-second sleep.
    expect($elapsedSeconds)->toBeLessThan(4.0);
});

test('doSend throws a TransportException when sendmail exits non-zero', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing(exitCode: 1);

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'exit code 1');

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);
});

test('doSend throws a TransportException for an empty sendmail_path instead of silently doing nothing', function (): void {
    $transport = new BoundedSendmailTransport('   ', 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'sendmail_path');
});

test('__toString identifies this as the bounded native transport', function (): void {
    $transport = new BoundedSendmailTransport('/bin/true', 5.0);

    expect((string) $transport)->toBe('native://default (bounded)');
});
