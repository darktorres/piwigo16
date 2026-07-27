<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\PwgXmlWriter;

/**
 * PwgXmlWriter is a small hand-rolled state machine (an open-tag flag +
 * an element-name stack + an indent level), not a DOM/XMLWriter wrapper.
 * Every expected string below was built by tracing that state machine
 * call by call: _end_prev() decides whether an element self-closes
 * (' />') or gets an explicit closing tag, based purely on whether
 * anything was written since start_element(); _indent()/_eol_indent()
 * only emit whitespace on the way *into* a nested element, never on the
 * way back out.
 */
test('an element with no content self-closes', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('foo');
    $writer->end_element('foo');

    expect($writer->getOutput())->toBe('<foo />');
});

test('an element with text content gets an explicit closing tag', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('foo');
    $writer->write_content('bar');
    $writer->end_element('foo');

    expect($writer->getOutput())->toBe('<foo>bar</foo>');
});

test('a tag name starting with a digit is prefixed with an underscore', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('123');
    $writer->end_element('123');

    expect($writer->getOutput())->toBe('<_123 />');
});

test('nested elements are indented with one tab per depth level, going in only', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('a');
    $writer->start_element('b');
    $writer->start_element('c');
    $writer->write_content('x');
    $writer->end_element('c');
    $writer->end_element('b');
    $writer->end_element('a');

    expect($writer->getOutput())->toBe("<a>\n\t<b>\n\t\t<c>x</c></b></a>");
});

test('write_attribute htmlspecialchars-escapes the value and stays inside the opening tag', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('img');
    $writer->write_attribute('id', 42);
    $writer->write_attribute('alt', 'a"b<c>d&e');
    $writer->end_element('img');

    expect($writer->getOutput())->toBe('<img id="42" alt="a&quot;b&lt;c&gt;d&amp;e" />');
});

test('write_cdata escapes an embedded ]]> terminator sequence', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('data');
    $writer->write_cdata('a]]>b');
    $writer->end_element('data');

    expect($writer->getOutput())->toBe('<data><![CDATA[a]]&gt;b]]></data>');
});

test('write_content coerces a non-scalar value to empty content instead of erroring', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('x');
    $writer->write_content(['not', 'scalar']);
    $writer->end_element('x');

    expect($writer->getOutput())->toBe('<x></x>');
});

test('end_element ignores its own argument and always closes the innermost stacked tag', function (): void {
    // end_element(string $x) never reads $x -- the closing tag name
    // always comes from array_pop($this->_elementStack). Passing a
    // completely different, mismatched name here proves that: if a
    // mutated implementation started using the argument instead of (or
    // in addition to) the stack, this would close with "totally-wrong-name"
    // instead of the real stack contents ('b' then 'a') and fail.
    $writer = new PwgXmlWriter();

    $writer->start_element('a');
    $writer->start_element('b');
    $writer->end_element('totally-wrong-name');
    $writer->end_element('another-wrong-name');

    expect($writer->getOutput())->toBe("<a>\n\t<b /></a>");
});
