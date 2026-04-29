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
        $w->start_element('foo');
        $w->end_element(null);
        self::assertStringContainsString('<foo', $w->getOutput());
        self::assertStringContainsString('/>', $w->getOutput());
    }

    public function testElementWithContent(): void
    {
        $w = $this->make();
        $w->start_element('name');
        $w->write_content('Alice');
        $w->end_element(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<name>', $out);
        self::assertStringContainsString('Alice', $out);
        self::assertStringContainsString('</name>', $out);
    }

    public function testWriteAttribute(): void
    {
        $w = $this->make();
        $w->start_element('item');
        $w->write_attribute('id', '42');
        $w->end_element(null);
        $out = $w->getOutput();
        self::assertStringContainsString('id="42"', $out);
    }

    public function testWriteCdata(): void
    {
        $w = $this->make();
        $w->start_element('raw');
        $w->write_cdata('<some>markup</some>');
        $w->end_element(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<![CDATA[', $out);
        self::assertStringContainsString('<some>markup</some>', $out);
        self::assertStringContainsString(']]>', $out);
    }

    public function testWriteContentEscapesHtml(): void
    {
        $w = $this->make();
        $w->start_element('t');
        $w->write_content('<dangerous>&text</dangerous>');
        $w->end_element(null);
        $out = $w->getOutput();
        self::assertStringNotContainsString('<dangerous>', $out);
        self::assertStringContainsString('&lt;', $out);
        self::assertStringContainsString('&amp;', $out);
    }

    public function testElementNameStartingWithDigitPrefixed(): void
    {
        $w = $this->make();
        $w->start_element('3d');
        $w->end_element(null);
        $out = $w->getOutput();
        self::assertStringContainsString('<_3d', $out);
    }

    public function testNestedElements(): void
    {
        $w = $this->make();
        $w->start_element('outer');
        $w->start_element('inner');
        $w->write_content('value');
        $w->end_element(null);
        $w->end_element(null);
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
        self::assertSame('&lt;b&gt;', $w->encode_attribute('<b>'));
        self::assertSame('&amp;', $w->encode_attribute('&'));
    }
}
