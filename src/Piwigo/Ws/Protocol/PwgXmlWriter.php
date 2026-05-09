<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

final class PwgXmlWriter
{
    private readonly string $_indentStr;
    /** @var array<mixed> */
    private array $_elementStack = [];
    private bool $_lastTagOpen;
    private int $_indentLevel;

    private string $_encodedXml = '';

    public function __construct()
    {
        $this->_elementStack = [];
        $this->_lastTagOpen = false;
        $this->_indentLevel = 0;

        $this->_encodedXml = '';
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

    public function endElement(string|null $x): void
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

    public function writeContent(float|int|string $value): void
    {
        $this->_end_prev(false);
        $this->_output(htmlspecialchars((string) $value));
    }

    public function writeCdata(string $value): void
    {
        $this->_end_prev(false);
        $this->_output(
            '<![CDATA['
      . str_replace(']]>', ']]&gt;', $value)
      . ']]>'
        );
    }

    public function writeAttribute(string $name, string $value): void
    {
        $this->_output(' '.$name.'="'.$this->encodeAttribute($value).'"');
    }

    public function encodeAttribute(string $value): string
    {
        return htmlspecialchars($value);
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
        $this->_output("\n");
    }

    public function _indent(): void
    {
        if ($this->_indentLevel > count($this->_elementStack)) {
            $this->_output(
                str_repeat($this->_indentStr, count($this->_elementStack))
            );
        }
    }

    public function _output(string $raw_content): void
    {
        $this->_encodedXml .= $raw_content;
    }
}
