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

test('a tag name starting with any digit 0-9 is prefixed with an underscore', function (string $digit): void {
    // The trailing non-digit letter also pins down which character
    // ord($name[0]) actually reads: a DecrementInteger mutant on that `0`
    // literal reads $name[-1] (the *last* char, 'x') instead, which is
    // never a digit here, so the mutant would leave the name unprefixed.
    $writer = new PwgXmlWriter();

    $writer->start_element($digit . 'x');
    $writer->end_element($digit . 'x');

    expect($writer->getOutput())->toBe('<_' . $digit . 'x />');
})->with(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']);

test('a tag name starting with the character just below the digit range is not prefixed', function (): void {
    // '/' (ASCII 47) is one below '0' (48), so diff = -1: pins the `>= 0`
    // lower boundary of the digit check.
    $writer = new PwgXmlWriter();

    $writer->start_element('/foo');
    $writer->end_element('/foo');

    expect($writer->getOutput())->toBe('</foo />');
});

test('a tag name starting with the character just above the digit range is not prefixed', function (): void {
    // ':' (ASCII 58) is one above '9' (57), so diff = 10: pins the
    // `<= 9` upper boundary of the digit check.
    $writer = new PwgXmlWriter();

    $writer->start_element(':foo');
    $writer->end_element(':foo');

    expect($writer->getOutput())->toBe('<:foo />');
});

test('a tag name is prefixed based on its first character, not its last', function (): void {
    // Isolates ord($name[0]) from ord($name[-1]): 'x' (first char) is not
    // a digit but '9' (last char) is, so a DecrementInteger mutant on the
    // $name[0] array offset (reading $name[-1] instead) would prefix this
    // name where the real code does not.
    $writer = new PwgXmlWriter();

    $writer->start_element('x9');
    $writer->end_element('x9');

    expect($writer->getOutput())->toBe('<x9 />');
});

test('end_element still emits indent whitespace before the closing tag when the indent level runs ahead of the element stack', function (): void {
    // Through the ordinary start_element()/end_element() call sequence,
    // $this->_indentLevel and count($this->_elementStack) are always kept
    // in lockstep (every increment is paired with a push, every decrement
    // with a pop), so the `_indentLevel > count($_elementStack)` check
    // inside end_element()'s close_tag branch never actually trips.
    // Every field here is `public` specifically so a test can drive the
    // state directly -- force the indent level one step ahead of the
    // stack to exercise that branch's call to _indent() for real.
    $writer = new PwgXmlWriter();
    $writer->_elementStack = ['a', 'b', 'c'];
    $writer->_indentLevel = 4;
    $writer->_lastTagOpen = false;

    $writer->end_element('c');

    expect($writer->getOutput())->toBe("\t\t</c>");
});

test('write_content casts a non-string scalar to a string before escaping it', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('n');
    $writer->write_content(42);
    $writer->end_element('n');

    expect($writer->getOutput())->toBe('<n>42</n>');
});

test('write_content escapes HTML-special characters in the value', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('n');
    $writer->write_content('<a>&"\'</a>');
    $writer->end_element('n');

    expect($writer->getOutput())->toBe('<n>&lt;a&gt;&amp;&quot;&#039;&lt;/a&gt;</n>');
});

test('write_cdata casts a non-string scalar to a string before writing it', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('n');
    $writer->write_cdata(42);
    $writer->end_element('n');

    expect($writer->getOutput())->toBe('<n><![CDATA[42]]></n>');
});

test('write_cdata coerces a non-scalar value to empty CDATA content instead of erroring', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('n');
    $writer->write_cdata(['not', 'scalar']);
    $writer->end_element('n');

    expect($writer->getOutput())->toBe('<n><![CDATA[]]></n>');
});

test('write_attribute coerces a non-scalar value to an empty attribute value instead of erroring', function (): void {
    $writer = new PwgXmlWriter();

    $writer->start_element('n');
    $writer->write_attribute('data', ['not', 'scalar']);
    $writer->end_element('n');

    expect($writer->getOutput())->toBe('<n data="" />');
});

test('a self-closing element correctly decrements the indent level for its parent\'s later explicit close tag', function (): void {
    // c self-closes with no content, decrementing _indentLevel inside
    // _end_prev() (line 124). b then closes via the *other* path
    // (end_element()'s own decrement) because it already has child
    // content, at a point where the element stack is non-empty (['a']).
    // If _end_prev()'s decrement instead incremented, _indentLevel would
    // be left 2 too high, flipping _indent()'s `>` check for b's closing
    // tag from false to true and emitting a spurious extra tab.
    $writer = new PwgXmlWriter();

    $writer->start_element('a');
    $writer->start_element('b');
    $writer->start_element('c');
    $writer->end_element('c');
    $writer->end_element('b');
    $writer->end_element('a');

    expect($writer->getOutput())->toBe("<a>\n\t<b>\n\t\t<c /></b></a>");
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
