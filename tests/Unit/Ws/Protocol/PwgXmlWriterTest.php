<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws\Protocol;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\Protocol\PwgXmlWriter;

final class PwgXmlWriterTest extends TestCase
{
    private function make(): PwgXmlWriter
    {
        return new PwgXmlWriter();
    }

    public function testSelfClosingElement(): void
    {
        $w = $this->make();
        $w->startElement('foo');
        $w->endElement(null);
        self::assertStringContainsString('<foo', $w->getOutput());
        self::assertStringContainsString('/>', $w->getOutput());
    }

    public function testElementWithContent(): void
    {
        $w = $this->make();
        $w->startElement('name');
        $w->writeContent('Alice');
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<name>', $out);
        self::assertStringContainsString('Alice', $out);
        self::assertStringContainsString('</name>', $out);
    }

    public function testWriteAttribute(): void
    {
        $w = $this->make();
        $w->startElement('item');
        $w->writeAttribute('id', '42');
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringContainsString('id="42"', $out);
    }

    public function testWriteCdata(): void
    {
        $w = $this->make();
        $w->startElement('raw');
        $w->writeCdata('<some>markup</some>');
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<![CDATA[', $out);
        self::assertStringContainsString('<some>markup</some>', $out);
        self::assertStringContainsString(']]>', $out);
    }

    public function testWriteContentEscapesHtml(): void
    {
        $w = $this->make();
        $w->startElement('t');
        $w->writeContent('<dangerous>&text</dangerous>');
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringNotContainsString('<dangerous>', $out);
        self::assertStringContainsString('&lt;', $out);
        self::assertStringContainsString('&amp;', $out);
    }

    public function testElementNameStartingWithDigitPrefixed(): void
    {
        $w = $this->make();
        $w->startElement('3d');
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<_3d', $out);
    }

    public function testNestedElements(): void
    {
        $w = $this->make();
        $w->startElement('outer');
        $w->startElement('inner');
        $w->writeContent('value');
        $w->endElement(null);
        $w->endElement(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<outer>', $out);
        self::assertStringContainsString('<inner>', $out);
        self::assertStringContainsString('value', $out);
        self::assertStringContainsString('</inner>', $out);
        self::assertStringContainsString('</outer>', $out);
    }

    public function testEncodeAttribute(): void
    {
        $w = $this->make();
        self::assertSame('&lt;b&gt;', $w->encodeAttribute('<b>'));
        self::assertSame('&amp;', $w->encodeAttribute('&'));
    }
}
