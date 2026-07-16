<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Encoder;

use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

/**
 * Base class for web service response encoder.
 */
abstract class PwgResponseEncoder
{
    /**
     * P23 batch 8e: relocated from include/ws_core.inc.php's
     * WS_XML_ATTRIBUTES define() -- the array-key marker this class's own
     * flatten() uses to distinguish XML-attribute values from XML-element
     * values in a response array.
     */
    public const string ATTRIBUTES_KEY = 'attributes_xml_';

    /** encodes the web service response to the appropriate output format
     * @param mixed $response the unencoded result of a service method call
     */
    abstract public function encodeResponse($response): string|false;

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
            $value = $value->_content;
        } elseif ($value instanceof PwgNamedStruct) {
            $value = $value->_content;
        }

        if (! is_array($value)) {
            return;
        }

        // is_struct() takes $value by reference with a mixed-typed
        // parameter, so PHPStan discards the is_array() narrowing above
        // across the call; re-assert it immediately after the call.
        $is_struct = self::is_struct($value);
        if (! is_array($value)) {
            return;
        }

        if ($is_struct) {
            if (isset($value[self::ATTRIBUTES_KEY]) and is_array($value[self::ATTRIBUTES_KEY])) {
                $value = array_merge($value, $value[self::ATTRIBUTES_KEY]);
                unset($value[self::ATTRIBUTES_KEY]);
            }
        }

        foreach ($value as $key => &$v) {
            self::flatten($v);
        }
    }
}
