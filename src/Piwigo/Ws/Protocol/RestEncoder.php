<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

use Override;
use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsErrorResponse;

/**
 * mixed values/params throughout are by design -- see the parent class's
 * own docblock.
 */
final class RestEncoder extends ResponseEncoder
{
    private ?XmlWriter $writer = null;

    /**
     * encode(), encodeArray() and encodeStruct() are only ever called
     * (directly or recursively) from encodeResponse(), which always sets
     * $writer before invoking them.
     */
    private function writer(): XmlWriter
    {
        assert($this->writer instanceof XmlWriter);
        return $this->writer;
    }

    #[Override]
    public function encodeResponse(mixed $response): string
    {
        if ($response instanceof WsErrorResponse) {
            $code = $response->code();
            $msg = htmlspecialchars($response->message());
            $ret = <<<XML
            <?xml version="1.0"?>
            <rsp stat="fail">
            \t<err code="{$code}" msg="{$msg}" />
            </rsp>
            XML;
            return $ret;
        }

        $this->writer = new XmlWriter();
        $this->encode($response);
        $ret = $this->writer()
            ->getOutput();
        $ret = <<<XML
        <?xml version="1.0" encoding="utf-8" ?>
        <rsp stat="ok">
        {$ret}
        </rsp>
        XML;

        return $ret;
    }

    #[Override]
    public function getContentType(): string
    {
        return 'text/xml';
    }

    /**
     * @param array<int, mixed> $data
     * @param array<string, int> $xml_attributes
     */
    public function encodeArray(array $data, string $itemName, array $xml_attributes = []): void
    {
        foreach ($data as $item) {
            $this->writer()
                ->startElement($itemName);
            $this->encode($item, $xml_attributes);
            $this->writer()
                ->endElement($itemName);
        }
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<array-key, int> $xml_attributes
     */
    public function encodeStruct(array $data, array $xml_attributes = []): void
    {
        foreach ($data as $name => $value) {
            if (is_numeric($name)) {
                continue;
            }
            if ($value === null) {
                continue;
            } // null means we dont put it
            if ($name === ResponseEncoder::ATTRIBUTES_KEY) {
                if (is_array($value)) {
                    foreach ($value as $attr_name => $attr_value) {
                        $this->writer()
                            ->writeAttribute((string) $attr_name, $attr_value);
                    }
                }
                unset($data[$name]);
            } elseif (isset($xml_attributes[$name])) {
                $this->writer()
                    ->writeAttribute($name, $value);
                unset($data[$name]);
            }
        }

        foreach ($data as $name => $value) {
            if (is_numeric($name)) {
                continue;
            }
            if ($value === null) {
                continue;
            } // null means we dont put it
            $this->writer()
                ->startElement($name);
            $this->encode($value);
            $this->writer()
                ->endElement($name);
        }
    }

    /**
     * @param array<string, int> $xml_attributes
     */
    public function encode(mixed $data, array $xml_attributes = []): void
    {
        switch (gettype($data)) {
            case 'NULL':
                $this->writer()
                    ->writeContent('');
                break;
            case 'boolean':
                $this->writer()
                    ->writeContent($data ? '1' : '0');
                break;
            case 'integer':
            case 'double':
                $this->writer()
                    ->writeContent($data);
                break;
            case 'string':
                $this->writer()
                    ->writeContent($data);
                break;
            case 'array':
                if (array_is_list($data)) {
                    $this->encodeArray($data, 'item');
                } else {
                    $this->encodeStruct($data, $xml_attributes);
                }
                break;
            case 'object':
                if ($data instanceof NamedArray) {
                    $this->encodeArray($data->content, $data->itemName, $data->xmlAttributes);
                } elseif ($data instanceof NamedStruct) {
                    $this->encodeStruct($data->content, $data->xmlAttributes);
                } else {
                    $this->encodeStruct(get_object_vars($data));
                }
                break;
            default:
                // Every gettype() outcome that could mean $data is an object
                // is already handled by the 'object' case above; only
                // resource/unknown-type values reach here.
                trigger_error('Invalid type ' . gettype($data), E_USER_WARNING);
        }
    }
}
