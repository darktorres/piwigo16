<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

/**
 * Low-level XML wire-format writer -- every `mixed` value/content param
 * here is genuinely by-design (an arbitrary WS response value being
 * serialized), same rationale as Encoder\PwgResponseEncoder's own
 * class-level docblock.
 */
final class PwgXmlWriter
{
    /**
     * @var string
     */
    public $indentStr;

    /**
     * @var string[]
     */
    public $elementStack;

    /**
     * @var bool
     */
    public $lastTagOpen;

    /**
     * @var int
     */
    public $indentLevel;

    /**
     * @var string
     */
    public $encodedXml;

    public function __construct()
    {
        $this->elementStack = [];
        $this->lastTagOpen = false;
        $this->indentLevel = 0;

        $this->encodedXml = '';
        $this->indentStr = "\t";
    }

    public function &getOutput(): string
    {
        return $this->encodedXml;
    }

    public function start_element(string $name): void
    {
        $this->end_prev(false);
        if ($this->elementStack !== []) {
            $this->eol_indent();
        }
        $this->indentLevel++;
        $this->indent();
        $diff = ord($name[0]) - ord('0');
        if ($diff >= 0 && $diff <= 9) {
            $name = '_' . $name;
        }
        $this->output('<' . $name);
        $this->lastTagOpen = true;
        $this->elementStack[] = $name;
    }

    public function end_element(string $x): void
    {
        $close_tag = $this->end_prev(true);
        $name = array_pop($this->elementStack);
        if ($close_tag) {
            $this->indentLevel--;
            $this->indent();
            //      $this->eol_indent();
            $this->output('</' . $name . '>');
        }
    }

    public function write_content(mixed $value): void
    {
        $this->end_prev(false);
        $value = is_scalar($value) ? (string) $value : '';
        $this->output(htmlspecialchars($value));
    }

    public function write_cdata(mixed $value): void
    {
        $this->end_prev(false);
        $value = is_scalar($value) ? (string) $value : '';
        $this->output(
            '<![CDATA['
      . str_replace(']]>', ']]&gt;', $value)
      . ']]>'
        );
    }

    public function write_attribute(string $name, mixed $value): void
    {
        $this->output(' ' . $name . '="' . $this->encode_attribute($value) . '"');
    }

    public function encode_attribute(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '');
    }

    private function end_prev(bool $done): bool
    {
        $ret = true;
        if ($this->lastTagOpen) {
            if ($done) {
                $this->indentLevel--;
                $this->output(' />');
                // $this->eol_indent();
                $ret = false;
            } else {
                $this->output('>');
            }
            $this->lastTagOpen = false;
        }
        return $ret;
    }

    private function eol_indent(): void
    {
        $this->output("\n");
    }

    private function indent(): void
    {
        if ($this->indentLevel > count($this->elementStack)) {
            $this->output(
                str_repeat($this->indentStr, count($this->elementStack))
            );
        }
    }

    private function output(string $raw_content): void
    {
        $this->encodedXml .= $raw_content;
    }
}
