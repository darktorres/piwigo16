<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_protocols;

final class PwgXmlWriter
{
    public bool $_indent = true;

    public string $_indentStr = "\t";

    public array $_elementStack = [];

    public bool $_lastTagOpen = false;

    public int $_indentLevel = 0;

    public string $_encodedXml = '';

    public function __construct() {}

    public function &getOutput(): string
    {
        return $this->_encodedXml;
    }

    public function start_element(
        string $name
    ): void {
        $this->_end_prev(false);

        if ($this->_elementStack !== []) {
            $this->_eol_indent();
        }

        $this->_indentLevel++;
        $this->_indent();
        $diff = ord($name[0]) - ord('0');

        if ($diff >= 0 &&
            $diff <= 9
        ) {
            $name = '_' . $name;
        }

        $this->_output('<' . $name);
        $this->_lastTagOpen = true;
        $this->_elementStack[] = $name;
    }

    public function end_element(
        string $x
    ): void {
        $close_tag = $this->_end_prev(true);
        $name = array_pop($this->_elementStack);

        if ($close_tag) {
            $this->_indentLevel--;
            $this->_indent();
            //      $this->_eol_indent();
            $this->_output('</' . $name . '>');
        }
    }

    public function write_content(
        string $value
    ): void {
        $this->_end_prev(false);
        $this->_output(htmlspecialchars($value));
    }

    // public function write_cdata(
    //     string|int|float|bool $value
    // ): void {
    //     $this->_end_prev(false);
    //     $value = (string) $value;
    //     $this->_output(
    //         '<![CDATA['
    //   . str_replace(']]>', ']]&gt;', $value)
    //   . ']]>'
    //     );
    // }

    public function write_attribute(
        string $name,
        string $value
    ): void {
        $this->_output(' ' . $name . '="' . $this->encode_attribute($value) . '"');
    }

    public function encode_attribute(
        string $value
    ): string {
        return htmlspecialchars($value);
    }

    public function _end_prev(
        bool $done
    ): bool {
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
        if ($this->_indent &&
            $this->_indentLevel > count($this->_elementStack)
        ) {
            $this->_output(
                str_repeat($this->_indentStr, count($this->_elementStack))
            );
        }
    }

    public function _output(
        string $raw_content
    ): void {
        $this->_encodedXml .= $raw_content;
    }
}
