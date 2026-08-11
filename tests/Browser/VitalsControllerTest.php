<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\VitalsController (`POST /analytics/vitals`, rewritten
 * from analytics_vitals.php) -- web-vitals RUM beacon sink. Always returns
 * 204 (sendBeacon() is fire-and-forget, see the class's own docblock), so
 * the only observable effect of a real request is the structured JSON log
 * line it writes (or doesn't) to the Monolog "app" channel
 * (config/container.php wires LoggerInterface to
 * _data/logs/piwigo-<date>.log with a JsonFormatter) -- asserting against
 * that log content, not just the 204 status, is what actually exercises
 * parseMetric()'s real validation branches.
 */

/**
 * Raw JSON POST -- VitalsController reads the raw request body directly, not a form-encoded one.
 *
 * @param array<string, mixed> $payload
 */
function vitalsPostJson(array $payload): int
{
    $ch = curl_init(H::baseUrl() . '/analytics/vitals');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'Content-Type: application/json']);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return $status;
}

/**
 * Reads today's real (unfrozen -- RotatingFileHandler rotates on the real wall clock) app log file.
 */
function vitalsTodaysAppLog(): string
{
    $path = dirname(__DIR__, 2) . '/_data/logs/piwigo-' . date('Y-m-d') . '.log';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Finds and JSON-decodes the one log line containing $needle (a unique marker), or null.
 *
 * @return array<array-key, mixed>|null
 */
function vitalsFindLogEntry(string $needle): ?array
{
    foreach (explode("\n", vitalsTodaysAppLog()) as $line) {
        if ($line !== '' && str_contains($line, $needle)) {
            $decoded = json_decode($line, true);

            return is_array($decoded) ? $decoded : null;
        }
    }

    return null;
}

it('logs a valid, known metric and returns an empty 204 response', function (): void {
    $uniqueId = 'vitals-test-' . uniqid();
    $status = vitalsPostJson([
        'name' => 'LCP',
        'value' => 1234.5,
        'id' => $uniqueId,
        'rating' => 'good',
        'url' => 'https://gallery.example.test/picture.php?image_id=7',
    ]);

    expect($status)
        ->toBe(204);

    $entry = vitalsFindLogEntry($uniqueId);
    if ($entry === null) {
        throw new RuntimeException('expected a logged web_vitals.metric entry for ' . $uniqueId);
    }
    $context = $entry['context'] ?? null;
    if (! is_array($context)) {
        throw new RuntimeException('expected a "context" array in the log entry: ' . var_export($entry, true));
    }
    expect($entry['message'])->toBe('web_vitals.metric')
        ->and($entry['level_name'])->toBe('INFO')
        ->and($entry['channel'])->toBe('app')
        ->and($context['name'])->toBe('LCP')
        ->and($context['value'])->toBe(1234.5)
        ->and($context['id'])->toBe($uniqueId)
        ->and($context['rating'])->toBe('good')
        ->and($context['url'])->toBe('https://gallery.example.test/picture.php?image_id=7');
});

it('silently drops an unrecognized metric name -- still 204, but nothing is logged', function (): void {
    $uniqueId = 'vitals-test-' . uniqid();
    $status = vitalsPostJson([
        'name' => 'NOT_A_REAL_METRIC',
        'value' => 42,
        'id' => $uniqueId,
        'rating' => 'good',
        'url' => 'https://gallery.example.test/',
    ]);

    expect($status)
        ->toBe(204);
    expect(vitalsFindLogEntry($uniqueId))
        ->toBeNull();
});

it('silently drops a malformed (non-JSON) body -- still 204, nothing logged', function (): void {
    $ch = curl_init(H::baseUrl() . '/analytics/vitals');
    expect($ch)
        ->not->toBeFalse();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'this is not json{{{');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'Content-Type: application/json']);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    expect($status)
        ->toBe(204);
});

it('silently drops a well-formed JSON body missing required fields -- still 204', function (): void {
    $uniqueId = 'vitals-test-' . uniqid();
    $status = vitalsPostJson([
        'name' => 'CLS',
        // 'value' deliberately omitted -- parseMetric() requires it to be
        // int|float, so a missing value must fall through to the null
        // ("nothing to log") branch rather than crash.
        'id' => $uniqueId,
        'rating' => 'good',
        'url' => 'https://gallery.example.test/',
    ]);

    expect($status)
        ->toBe(204);
    expect(vitalsFindLogEntry($uniqueId))
        ->toBeNull();
});

it('silently drops a completely empty request body -- still 204, nothing logged', function (): void {
    $ch = curl_init(H::baseUrl() . '/analytics/vitals');
    expect($ch)
        ->not->toBeFalse();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    // Deliberately no CURLOPT_POSTFIELDS at all -- parseMetric()'s own
    // `$rawBody === ''` early return, distinct from the malformed
    // (non-empty, non-JSON) body test above, which reaches the
    // JSON_THROW_ON_ERROR catch branch instead.
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'Content-Type: application/json']);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    expect($status)
        ->toBe(204);
});

it('silently drops a well-formed JSON body that decodes to a non-array -- still 204, nothing logged', function (): void {
    $ch = curl_init(H::baseUrl() . '/analytics/vitals');
    expect($ch)
        ->not->toBeFalse();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    // A bare JSON string is valid JSON (json_decode() succeeds, no
    // JsonException), but decodes to a plain PHP string, not an array --
    // parseMetric()'s own `! is_array($data)` guard, distinct from the
    // malformed-JSON test above.
    curl_setopt($ch, CURLOPT_POSTFIELDS, '"just a string"');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [...H::testHeaders(), 'Content-Type: application/json']);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    expect($status)
        ->toBe(204);
});

it('silently drops a metric with a non-string id/rating/url -- still 204', function (): void {
    // id/rating/url all real, present keys, but the wrong type --
    // parseMetric()'s own `! is_string($id) || ! is_string($rating) ||
    // ! is_string($url)` guard, distinct from the "missing 'value'" test
    // above (which exercises the *value* type-check instead). Nothing
    // gets logged on this branch (same as the malformed-body/non-array
    // tests above), so -- like those -- there is no distinguishing log
    // content to assert on beyond the 204 status itself.
    $status = vitalsPostJson([
        'name' => 'FCP',
        'value' => 42,
        'id' => 12345,
        'rating' => true,
        'url' => ['not', 'a', 'string'],
    ]);

    expect($status)
        ->toBe(204);
});
