<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\XmlWriter;

/**
 * XmlWriter is a small hand-rolled state machine (an open-tag flag +
 * an element-name stack + an indent level), not a DOM/XMLWriter wrapper.
 * Every expected string below was built by tracing that state machine
 * call by call: endPrev() decides whether an element self-closes
 * (' />') or gets an explicit closing tag, based purely on whether
 * anything was written since startElement(); indent()/eolIndent()
 * only emit whitespace on the way *into* a nested element, never on the
 * way back out.
 */
test('an element with no content self-closes', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('foo');
    $writer->endElement('foo');

    expect($writer->getOutput())
        ->toBe('<foo />');
});

test('an element with text content gets an explicit closing tag', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('foo');
    $writer->writeContent('bar');
    $writer->endElement('foo');

    expect($writer->getOutput())
        ->toBe('<foo>bar</foo>');
});

test('a tag name starting with a digit is prefixed with an underscore', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('123');
    $writer->endElement('123');

    expect($writer->getOutput())
        ->toBe('<_123 />');
});

test('nested elements are indented with one tab per depth level, going in only', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('a');
    $writer->startElement('b');
    $writer->startElement('c');
    $writer->writeContent('x');
    $writer->endElement('c');
    $writer->endElement('b');
    $writer->endElement('a');

    expect($writer->getOutput())
        ->toBe("<a>\n\t<b>\n\t\t<c>x</c></b></a>");
});

test('writeAttribute htmlspecialchars-escapes the value and stays inside the opening tag', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('img');
    $writer->writeAttribute('id', 42);
    $writer->writeAttribute('alt', 'a"b<c>d&e');
    $writer->endElement('img');

    expect($writer->getOutput())
        ->toBe('<img id="42" alt="a&quot;b&lt;c&gt;d&amp;e" />');
});

test('writeCdata escapes an embedded ]]> terminator sequence', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('data');
    $writer->writeCdata('a]]>b');
    $writer->endElement('data');

    expect($writer->getOutput())
        ->toBe('<data><![CDATA[a]]&gt;b]]></data>');
});

test('writeContent coerces a non-scalar value to empty content instead of erroring', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('x');
    $writer->writeContent(['not', 'scalar']);
    $writer->endElement('x');

    expect($writer->getOutput())
        ->toBe('<x></x>');
});

test('a tag name starting with any digit 0-9 is prefixed with an underscore', function (string $digit): void {
    // The trailing non-digit letter also pins down which character
    // ord($name[0]) actually reads: a DecrementInteger mutant on that `0`
    // literal reads $name[-1] (the *last* char, 'x') instead, which is
    // never a digit here, so the mutant would leave the name unprefixed.
    $writer = new XmlWriter();

    $writer->startElement($digit . 'x');
    $writer->endElement($digit . 'x');

    expect($writer->getOutput())
        ->toBe('<_' . $digit . 'x />');
})->with(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']);

test('a tag name starting with the character just below the digit range is not prefixed', function (): void {
    // '/' (ASCII 47) is one below '0' (48), so diff = -1: pins the `>= 0`
    // lower boundary of the digit check.
    $writer = new XmlWriter();

    $writer->startElement('/foo');
    $writer->endElement('/foo');

    expect($writer->getOutput())
        ->toBe('</foo />');
});

test('a tag name starting with the character just above the digit range is not prefixed', function (): void {
    // ':' (ASCII 58) is one above '9' (57), so diff = 10: pins the
    // `<= 9` upper boundary of the digit check.
    $writer = new XmlWriter();

    $writer->startElement(':foo');
    $writer->endElement(':foo');

    expect($writer->getOutput())
        ->toBe('<:foo />');
});

test('a tag name is prefixed based on its first character, not its last', function (): void {
    // Isolates ord($name[0]) from ord($name[-1]): 'x' (first char) is not
    // a digit but '9' (last char) is, so a DecrementInteger mutant on the
    // $name[0] array offset (reading $name[-1] instead) would prefix this
    // name where the real code does not.
    $writer = new XmlWriter();

    $writer->startElement('x9');
    $writer->endElement('x9');

    expect($writer->getOutput())
        ->toBe('<x9 />');
});

test('endElement still emits indent whitespace before the closing tag when the indent level runs ahead of the element stack', function (): void {
    // Through the ordinary startElement()/endElement() call sequence,
    // $this->indentLevel and count($this->elementStack) are always kept
    // in lockstep (every increment is paired with a push, every decrement
    // with a pop), so the `indentLevel > count($elementStack)` check
    // inside endElement()'s close_tag branch never actually trips.
    // Every field here is `public` specifically so a test can drive the
    // state directly -- force the indent level one step ahead of the
    // stack to exercise that branch's call to indent() for real.
    $writer = new XmlWriter();
    $writer->elementStack = ['a', 'b', 'c'];
    $writer->indentLevel = 4;
    $writer->lastTagOpen = false;

    $writer->endElement('c');

    expect($writer->getOutput())
        ->toBe("\t\t</c>");
});

test('writeContent casts a non-string scalar to a string before escaping it', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('n');
    $writer->writeContent(42);
    $writer->endElement('n');

    expect($writer->getOutput())
        ->toBe('<n>42</n>');
});

test('writeContent escapes HTML-special characters in the value', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('n');
    $writer->writeContent('<a>&"\'</a>');
    $writer->endElement('n');

    expect($writer->getOutput())
        ->toBe('<n>&lt;a&gt;&amp;&quot;&#039;&lt;/a&gt;</n>');
});

test('writeCdata casts a non-string scalar to a string before writing it', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('n');
    $writer->writeCdata(42);
    $writer->endElement('n');

    expect($writer->getOutput())
        ->toBe('<n><![CDATA[42]]></n>');
});

test('writeCdata coerces a non-scalar value to empty CDATA content instead of erroring', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('n');
    $writer->writeCdata(['not', 'scalar']);
    $writer->endElement('n');

    expect($writer->getOutput())
        ->toBe('<n><![CDATA[]]></n>');
});

test('writeAttribute coerces a non-scalar value to an empty attribute value instead of erroring', function (): void {
    $writer = new XmlWriter();

    $writer->startElement('n');
    $writer->writeAttribute('data', ['not', 'scalar']);
    $writer->endElement('n');

    expect($writer->getOutput())
        ->toBe('<n data="" />');
});

test('a self-closing element correctly decrements the indent level for its parent\'s later explicit close tag', function (): void {
    // c self-closes with no content, decrementing indentLevel inside
    // endPrev() (line 124). b then closes via the *other* path
    // (endElement()'s own decrement) because it already has child
    // content, at a point where the element stack is non-empty (['a']).
    // If endPrev()'s decrement instead incremented, indentLevel would
    // be left 2 too high, flipping indent()'s `>` check for b's closing
    // tag from false to true and emitting a spurious extra tab.
    $writer = new XmlWriter();

    $writer->startElement('a');
    $writer->startElement('b');
    $writer->startElement('c');
    $writer->endElement('c');
    $writer->endElement('b');
    $writer->endElement('a');

    expect($writer->getOutput())
        ->toBe("<a>\n\t<b>\n\t\t<c /></b></a>");
});

test('endElement ignores its own argument and always closes the innermost stacked tag', function (): void {
    // endElement(string $x) never reads $x -- the closing tag name
    // always comes from array_pop($this->elementStack). Passing a
    // completely different, mismatched name here proves that: if a
    // mutated implementation started using the argument instead of (or
    // in addition to) the stack, this would close with "totally-wrong-name"
    // instead of the real stack contents ('b' then 'a') and fail.
    $writer = new XmlWriter();

    $writer->startElement('a');
    $writer->startElement('b');
    $writer->endElement('totally-wrong-name');
    $writer->endElement('another-wrong-name');

    expect($writer->getOutput())
        ->toBe("<a>\n\t<b /></a>");
});
