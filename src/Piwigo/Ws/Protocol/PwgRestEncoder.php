<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

class PwgRestEncoder extends PwgResponseEncoder
{
    private ?PwgXmlWriter $_writer = null;

    /**
     * encode(), encode_array() and encode_struct() are only ever called
     * (directly or recursively) from encodeResponse(), which always sets
     * $_writer before invoking them.
     */
    private function writer(): PwgXmlWriter
    {
        assert($this->_writer instanceof PwgXmlWriter);
        return $this->_writer;
    }

    #[\Override]
    public function encodeResponse($response): string
    {
        if ($response instanceof PwgError) {
            $ret = '<?xml version="1.0"?>
<rsp stat="fail">
	<err code="' . $response->code() . '" msg="' . htmlspecialchars($response->message()) . '" />
</rsp>';
            return $ret;
        }

        $this->_writer = new PwgXmlWriter();
        $this->encode($response);
        $ret = $this->writer()
            ->getOutput();
        $ret = '<?xml version="1.0" encoding="' . \Piwigo\Core\CharsetHelper::getPwgCharset() . '" ?>
<rsp stat="ok">
' . $ret . '
</rsp>';

        return $ret;
    }

    #[\Override]
    public function getContentType(): string
    {
        return 'text/xml';
    }

    /**
     * @param array<int, mixed> $data
     * @param array<string, int> $xml_attributes
     */
    public function encode_array(array $data, string $itemName, array $xml_attributes = []): void
    {
        foreach ($data as $item) {
            $this->writer()
                ->start_element($itemName);
            $this->encode($item, $xml_attributes);
            $this->writer()
                ->end_element($itemName);
        }
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, int> $xml_attributes
     */
    public function encode_struct(array $data, bool $skip_underscore, array $xml_attributes = []): void
    {
        foreach ($data as $name => $value) {
            if (is_numeric($name)) {
                continue;
            }
            if ($skip_underscore and $name[0] == '_') {
                continue;
            }
            if ($value === null) {
                continue;
            } // null means we dont put it
            if ($name == WS_XML_ATTRIBUTES) {
                if (is_array($value)) {
                    foreach ($value as $attr_name => $attr_value) {
                        $this->writer()
                            ->write_attribute((string) $attr_name, $attr_value);
                    }
                }
                unset($data[$name]);
            } elseif (isset($xml_attributes[$name])) {
                $this->writer()
                    ->write_attribute($name, $value);
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
            if ($value === null) {
                continue;
            } // null means we dont put it
            $this->writer()
                ->start_element($name);
            $this->encode($value);
            $this->writer()
                ->end_element($name);
        }
    }

    /**
     * @param array<string, int> $xml_attributes
     */
    public function encode(mixed $data, array $xml_attributes = []): void
    {
        switch (gettype($data)) {
            case 'null':
            case 'NULL':
                $this->writer()
                    ->write_content('');
                break;
            case 'boolean':
                $this->writer()
                    ->write_content($data ? '1' : '0');
                break;
            case 'integer':
            case 'double':
                $this->writer()
                    ->write_content($data);
                break;
            case 'string':
                $this->writer()
                    ->write_content($data);
                break;
            case 'array':
                if (array_is_list($data)) {
                    $this->encode_array($data, 'item');
                } else {
                    $this->encode_struct($data, false, $xml_attributes);
                }
                break;
            case 'object':
                if ($data instanceof PwgNamedArray) {
                    $this->encode_array($data->_content, $data->_itemName, $data->_xmlAttributes);
                } elseif ($data instanceof PwgNamedStruct) {
                    $this->encode_struct($data->_content, false, $data->_xmlAttributes);
                } else {
                    $this->encode_struct(get_object_vars($data), true);
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
