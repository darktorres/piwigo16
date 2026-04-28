<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

class PwgRestEncoder extends PwgResponseEncoder
{
    private ?PwgXmlWriter $_writer = null;
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
        $ret = $this->_writer->getOutput();
        $ret = '<?xml version="1.0" encoding="'.get_pwg_charset().'" ?>
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
    public function encode_array(mixed $data, string $itemName, array $xml_attributes = []): void
    {
        foreach ($data as $item) {
            $this->_writer->start_element($itemName);
            $this->encode($item, $xml_attributes);
            $this->_writer->end_element($itemName);
        }
    }

        /**
 * @param array<mixed> $data
 * @param array<mixed> $xml_attributes
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
            if (is_null($value)) {
                continue;
            } // null means we dont put it
            if ($name == WS_XML_ATTRIBUTES) {
                foreach ($value as $attr_name => $attr_value) {
                    $this->_writer->write_attribute($attr_name, $attr_value);
                }
                unset($data[$name]);
            } elseif (isset($xml_attributes[$name])) {
                $this->_writer->write_attribute($name, $value);
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
            $this->_writer->start_element($name);
            $this->encode($value);
            $this->_writer->end_element($name);
        }
    }

        /** @param array<mixed> $xml_attributes */
    public function encode(mixed $data, array $xml_attributes = []): void
    {
        switch (gettype($data)) {
            case 'null':
            case 'NULL':
                $this->_writer->write_content('');
                break;
            case 'boolean':
                $this->_writer->write_content($data ? '1' : '0');
                break;
            case 'integer':
            case 'double':
                $this->_writer->write_content($data);
                break;
            case 'string':
                $this->_writer->write_content($data);
                break;
            case 'array':
                $is_array = range(0, count($data) - 1) === array_keys($data);
                if ($is_array) {
                    $this->encode_array($data, 'item');
                } else {
                    $this->encode_struct($data, false, $xml_attributes);
                }
                break;
            case 'object':
                match (strtolower(@$data::class)) {
                    'pwgnamedarray' => $this->encode_array($data->_content, $data->_itemName, $data->_xmlAttributes),
                    'pwgnamedstruct' => $this->encode_struct($data->_content, false, $data->_xmlAttributes),
                    default => $this->encode_struct(get_object_vars($data), true),
                };
                break;
            default:
                trigger_error('Invalid type '. gettype($data).' '.@$data::class, E_USER_WARNING);
        }
    }
}
