<?php

declare(strict_types=1);

namespace Piwigo\Ws\Encoder;

use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

/**
 *
 * Base class for web service response encoder.
 */
abstract class PwgResponseEncoder
{
    /** encodes the web service response to the appropriate output format
     * @param mixed $response the unencoded result of a service method call
     */
    abstract public function encodeResponse(mixed $response): mixed;

    /** default "Content-Type" http header for this kind of response format
     */
    abstract public function getContentType(): string;

    /**
     * returns true if the parameter is a 'struct' (php array type whose keys are
     * NOT consecutive integers starting with 0)
     */
    public static function is_struct(mixed &$data): bool
    {
        if (is_array($data)) {
            if (range(0, count($data) - 1) !== array_keys($data)) { # string keys, unordered, non-incremental keys, .. - whatever, make object
                return true;
            }
        }
        return false;
    }

    /**
     * removes all XML formatting from $response (named array, named structs, etc)
     * usually called by every response encoder, except rest xml.
     */
    public static function flattenResponse(mixed &$value): void
    {
        self::flatten($value);
    }

    private static function flatten(mixed &$value): void
    {
        if ($value instanceof PwgNamedArray) {
            $value = $value->getContent();
        } elseif ($value instanceof PwgNamedStruct) {
            $value = $value->getContent();
        }

        if (!is_array($value)) {
            return;
        }

        /** @var array<mixed> $arr */
        $arr = $value;
        if (self::is_struct($value)) {
            if (isset($arr[WS_XML_ATTRIBUTES])) {
                $xmlAttrs = $arr[WS_XML_ATTRIBUTES];
                if (is_array($xmlAttrs)) {
                    $value = array_merge($arr, $xmlAttrs);
                    /** @var array<mixed> $arr */
                    $arr = $value;
                    unset($arr[WS_XML_ATTRIBUTES]);
                    $value = $arr;
                }
            }
        }

        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => &$v) {
            self::flatten($v);
        }
        unset($v);
    }
}
