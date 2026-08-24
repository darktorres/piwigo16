<?php

declare(strict_types=1);

/**
 * A minimal, disposable ESMTP server for MailServiceTest.php's real-Transport
 * tests (run as a real subprocess via proc_open(), same technique as
 * ResponseEmitterTest's local `php -S` server / HttpClientServiceTest's own
 * local-server pattern). Speaks just enough of the protocol for Symfony
 * Mailer's real EsmtpTransport to consider a send successful or rejected --
 * no real message ever leaves this machine.
 *
 * Args: <mode: success|reject_rcpt> <port> [markerFile] [readyFile]
 * markerFile (if given) is written the instant a connection is accepted, so
 * a test can prove the real Transport was actually reached even when the
 * observable return value alone wouldn't distinguish "reached and skipped"
 * from "never reached". readyFile (if given) is written right after the
 * socket is bound/listening, BEFORE the blocking accept() call -- the
 * caller must poll for its existence rather than probing the port with its
 * own throwaway connection, since this server only ever accepts ONE
 * connection total: a probe connection would itself consume that one
 * accept() and starve the real client that follows.
 */
$argv = $_SERVER['argv'] ?? [];
if (! is_array($argv)) {
    fwrite(STDERR, "no argv\n");
    exit(1);
}
$args = array_values(array_filter($argv, is_string(...)));

$mode = $args[1] ?? 'success';
$port = (int) ($args[2] ?? '0');
$markerFile = $args[3] ?? null;
$readyFile = $args[4] ?? null;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
if ($readyFile !== null) {
    file_put_contents($readyFile, 'ready');
}

$conn = stream_socket_accept($server, 30);
if ($conn === false) {
    fwrite(STDERR, "accept failed\n");
    exit(1);
}
if ($markerFile !== null) {
    file_put_contents($markerFile, 'connected');
}
stream_set_timeout($conn, 5);

/**
 * @param resource $conn
 */
function mailServiceTestFakeSmtpReadLine($conn): string
{
    $line = fgets($conn);

    return $line === false ? '' : $line;
}

fwrite($conn, "220 fake.smtp.test ESMTP\r\n");
mailServiceTestFakeSmtpReadLine($conn); // EHLO
fwrite($conn, "250-fake.smtp.test\r\n250 HELP\r\n");
mailServiceTestFakeSmtpReadLine($conn); // MAIL FROM
fwrite($conn, "250 OK\r\n");
mailServiceTestFakeSmtpReadLine($conn); // RCPT TO

if ($mode === 'reject_rcpt') {
    fwrite($conn, "550 No such user\r\n");
    fclose($conn);
    fclose($server);
    exit(0);
}

fwrite($conn, "250 OK\r\n");
mailServiceTestFakeSmtpReadLine($conn); // DATA
fwrite($conn, "354 Send message\r\n");
$buf = '';
while (! str_ends_with($buf, "\r\n.\r\n")) {
    $chunk = fgets($conn);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $buf .= $chunk;
}
fwrite($conn, "250 OK: queued\r\n");
fclose($conn);
fclose($server);
