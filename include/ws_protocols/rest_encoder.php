<?php

declare(strict_types=1);

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+


class PwgXmlWriter
{
    /** @var bool */
    public $_indent;
    /** @var string */
    public $_indentStr;
    /** @var array<mixed> */
    public array $_elementStack = [];
    /** @var bool */
    public $_lastTagOpen;
    /**
     * @var int
     */
    public $_indentLevel;

    public string $_encodedXml = '';

    public function __construct()
    {
        $this->_elementStack = [];
        $this->_lastTagOpen = false;
        $this->_indentLevel = 0;

        $this->_encodedXml = '';
        $this->_indent = true;
        $this->_indentStr = "\t";
    }

    public function &getOutput(): string
    {
        return $this->_encodedXml;
    }


    public function startElement(string $name): void
    {
        $this->_end_prev(false);
        if (!empty($this->_elementStack)) {
            $this->_eol_indent();
        }
        $this->_indentLevel++;
        $this->_indent();
        $diff = ord($name[0]) - ord('0');
        if ($diff >= 0 && $diff <= 9) {
            $name = '_'.$name;
        }
        $this->_output('<'.$name);
        $this->_lastTagOpen = true;
        $this->_elementStack[] = $name;
    }

    public function endElement(mixed $x): void
    {
        $close_tag = $this->_end_prev(true);
        $name = array_pop($this->_elementStack);
        if ($close_tag) {
            $this->_indentLevel--;
            $this->_indent();
            //      $this->_eol_indent();
            $this->_output('</' . (is_string($name) ? $name : '') . '>');
        }
    }

    public function writeContent(mixed $value): void
    {
        $this->_end_prev(false);
        $str = is_scalar($value) || $value === null ? (string) $value : '';
        $this->_output(htmlspecialchars($str));
    }

    public function writeCdata(mixed $value): void
    {
        $this->_end_prev(false);
        $str = is_scalar($value) || $value === null ? (string) $value : '';
        $this->_output(
            '<![CDATA['
      . str_replace(']]>', ']]&gt;', $str)
      . ']]>'
        );
    }

    public function writeAttribute(string $name, mixed $value): void
    {
        $this->_output(' '.$name.'="'.$this->encodeAttribute($value).'"');
    }

    public function encodeAttribute(mixed $value): string
    {
        $str = is_scalar($value) || $value === null ? (string) $value : '';
        return htmlspecialchars($str);
    }

    public function _end_prev(bool $done): bool
    {
        $ret = true;
        if ($this->_lastTagOpen) {
            if ($done) {
                $this->_indentLevel--;
                $this->_output(' />');
                //$this->_eol_indent();
                $ret = false;
            } else {
                $this->_output('>');
            }
            $this->_lastTagOpen = false;
        }
        return $ret;
    }

    public function _eol_indent(): void
    {
        if ($this->_indent) {
            $this->_output("\n");
        }
    }

    public function _indent(): void
    {
        if ($this->_indent and
            $this->_indentLevel > count($this->_elementStack)) {
            $this->_output(
                str_repeat((string) $this->_indentStr, count($this->_elementStack))
            );
        }
    }

    public function _output(string $raw_content): void
    {
        $this->_encodedXml .= $raw_content;
    }
}

class PwgRestEncoder extends PwgResponseEncoder
{
    private ?\Piwigo\Ws\Protocol\PwgXmlWriter $_writer = null;

    private function writer(): \Piwigo\Ws\Protocol\PwgXmlWriter
    {
        assert($this->_writer !== null);
        return $this->_writer;
    }

    public function encodeResponse($response): string
    {
        if ($response instanceof PwgError) {
            $ret = '<?xml version="1.0"?>
<rsp stat="fail">
	<err code="'.$response->code().'" msg="'.htmlspecialchars((string) $response->message()).'" />
</rsp>';
            return $ret;
        }

        $this->_writer = new \Piwigo\Ws\Protocol\PwgXmlWriter();
        $this->encode($response);
        $ret = $this->writer()->getOutput();
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
