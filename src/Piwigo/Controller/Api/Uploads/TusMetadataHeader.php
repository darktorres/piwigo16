<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

/**
 * Parses the tus 1.0.0 `Upload-Metadata` request header -- a
 * comma-separated list of `key base64(value)` pairs (the value half is
 * optional, for flag-only keys; not used by any key this server reads,
 * so a missing value decodes to `''`).
 *
 * @see https://tus.io/protocols/resumable-upload#upload-metadata
 */
final class TusMetadataHeader
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $header): array
    {
        $result = [];

        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            [$key, $encodedValue] = array_pad(explode(' ', $pair, 2), 2, null);
            if (! is_string($key) || $key === '') {
                continue;
            }

            $decoded = is_string($encodedValue) ? base64_decode($encodedValue, true) : '';
            $result[$key] = $decoded === false ? '' : $decoded;
        }

        return $result;
    }
}
