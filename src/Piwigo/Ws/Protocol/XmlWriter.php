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
 * serialized), same rationale as Encoder\ResponseEncoder's own
 * class-level docblock.
 */
final class XmlWriter
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

    public function startElement(string $name): void
    {
        $this->endPrev(false);
        if ($this->elementStack !== []) {
            $this->eolIndent();
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

    public function endElement(string $x): void
    {
        $close_tag = $this->endPrev(true);
        $name = array_pop($this->elementStack);
        if ($close_tag) {
            $this->indentLevel--;
            $this->indent();
            //      $this->eolIndent();
            $this->output('</' . $name . '>');
        }
    }

    public function writeContent(mixed $value): void
    {
        $this->endPrev(false);
        $value = is_scalar($value) ? (string) $value : '';
        $this->output(htmlspecialchars($value));
    }

    public function writeCdata(mixed $value): void
    {
        $this->endPrev(false);
        $value = is_scalar($value) ? (string) $value : '';
        $this->output(
            '<![CDATA['
      . str_replace(']]>', ']]&gt;', $value)
      . ']]>'
        );
    }

    public function writeAttribute(string $name, mixed $value): void
    {
        $this->output(' ' . $name . '="' . $this->encodeAttribute($value) . '"');
    }

    public function encodeAttribute(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '');
    }

    private function endPrev(bool $done): bool
    {
        $ret = true;
        if ($this->lastTagOpen) {
            if ($done) {
                $this->indentLevel--;
                $this->output(' />');
                // $this->eolIndent();
                $ret = false;
            } else {
                $this->output('>');
            }
            $this->lastTagOpen = false;
        }
        return $ret;
    }

    private function eolIndent(): void
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
