<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

class PwgRestEncoder extends PwgResponseEncoder
{
    private ?PwgXmlWriter $_writer = null;

    private function writer(): PwgXmlWriter
    {
        assert($this->_writer !== null);
        return $this->_writer;
    }

    public function encodeResponse(mixed $response): string
    {
        if ($response instanceof PwgError) {
            $ret = '<?xml version="1.0"?>
<rsp stat="fail">
	<err code="'.$response->code().'" msg="'.htmlspecialchars((string) $response->message()).'" />
</rsp>';
            return $ret;
        }

        $this->_writer = new PwgXmlWriter();
        $this->encode($response);
        $ret = $this->writer()->getOutput();
        $ret = '<?xml version="1.0" encoding="utf-8" ?>
<rsp stat="ok">
'.$ret.'
</rsp>';

        return $ret;
    }

    public function getContentType(): string
    {
        return 'text/xml';
    }

    /** @param array<mixed> $xml_attributes */
    public function encodeArray(mixed $data, string $itemName, array $xml_attributes = []): void
    {
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $item) {
            $this->writer()->startElement($itemName);
            $this->encode($item, $xml_attributes);
            $this->writer()->endElement($itemName);
        }
    }

    /**
 * @param array<mixed> $data
 * @param array<mixed> $xml_attributes
 */
    public function encodeStruct(array $data, bool $skip_underscore, array $xml_attributes = []): void
    {
        foreach ($data as $name => $value) {
            if (is_numeric($name)) {
                continue;
            }
            if ($skip_underscore and $name[0] == '_') {
                continue;
            }
            if (is_null($value)) {
                continue;
            } // null means we dont put it
            if ($name == WS_XML_ATTRIBUTES) {
                if (is_array($value)) {
                    foreach ($value as $attr_name => $attr_value) {
                        $this->writer()->writeAttribute((string) $attr_name, $attr_value);
                    }
                }
                unset($data[$name]);
            } elseif (isset($xml_attributes[$name])) {
                $this->writer()->writeAttribute($name, $value);
                unset($data[$name]);
            }
        }

        foreach ($data as $name => $value) {
            if (is_numeric($name)) {
                continue;
            }
            if ($skip_underscore and $name[0] == '_') {
                continue;
            }
            if (is_null($value)) {
                continue;
            } // null means we dont put it
            $this->writer()->startElement($name);
            $this->encode($value);
            $this->writer()->endElement($name);
        }
    }

    /** @param array<mixed> $xml_attributes */
    public function encode(mixed $data, array $xml_attributes = []): void
    {
        switch (gettype($data)) {
            case 'null':
            case 'NULL':
                $this->writer()->writeContent('');
                break;
            case 'boolean':
                $this->writer()->writeContent($data ? '1' : '0');
                break;
            case 'integer':
            case 'double':
                $this->writer()->writeContent($data);
                break;
            case 'string':
                $this->writer()->writeContent($data);
                break;
            case 'array':
                $is_array = range(0, count($data) - 1) === array_keys($data);
                if ($is_array) {
                    $this->encodeArray($data, 'item');
                } else {
                    $this->encodeStruct($data, false, $xml_attributes);
                }
                break;
            case 'object':
                if ($data instanceof PwgNamedArray) {
                    $this->encodeArray($data->getContent(), $data->getItemName(), $data->getXmlAttributes());
                } elseif ($data instanceof PwgNamedStruct) {
                    $content = $data->getContent();
                    $this->encodeStruct(is_array($content) ? $content : [], false, $data->getXmlAttributes());
                } else {
                    $this->encodeStruct(get_object_vars($data), true);
                }
                break;
            default:
                trigger_error('Invalid type '. gettype($data), E_USER_WARNING);
        }
    }
}
