<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;

/**
 * Piwigo\Template\Latte\PiwigoExtension -- individual filter/function
 * correctness. LatteEngineWiringTest.php's own docblock deliberately
 * limits itself to end-to-end wiring proof ("not testing individual
 * filter/function correctness"), which left every method here with zero
 * dedicated coverage -- this file closes that gap.
 *
 * `combineScript`/`combineCss`/`getCombinedScripts`/`getCombinedCss`/
 * `defineDerivative`/`htmlHead`/`footerScript`/
 * `localCssRules`/`once` are deliberately NOT retested here
 * -- they're thin `$this->template->x(...)` delegates with their own
 * real coverage in TemplateInstanceTest.php; duplicating it against a
 * second, PiwigoExtension-constructed Template would just be the same
 * assertions with extra indirection. `htmlOptions()`/`htmlRadios()`
 * are real, substantial ports of Smarty's own stdlib plugins with no
 * Template involvement at all, so they get real coverage here.
 */
function piwigo_extension_test_build(): PiwigoExtension
{
    $currentConfig = CurrentConfigTestFactory::get();
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    return new PiwigoExtension(
        TemplateTestFactory::build(),
        LangTestFactory::get(),
        new AccessLevelChecker($currentUser, $currentConfig),
        UrlServiceTestFactory::build(),
    );
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-piwigo-extension-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

// --- translate / translateDec -------------------------------------------

test('translate falls back to sprintf-ing the raw key when no translation is loaded', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->translate('Hello %s', 'World'))
        ->toBe('Hello World');
});

test('translate casts an Html-wrapped substitution arg to string instead of passing the object through', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->translate('Value: %s', new Html('<b>x</b>')))
        ->toBe('Value: <b>x</b>');
});

test('translateDec delegates to Lang::plural(), picking the plural form for a count other than 1', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->translateDec(1, '%d item', '%d items'))
        ->toBe('1 item')
        ->and($extension->translateDec(5, '%d item', '%d items'))
        ->toBe('5 items');
});

test('translateDec treats a non-numeric count as 0, matching Lang::plural()\'s own coercion', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->translateDec(null, '%d item', '%d items'))
        ->toBe('0 items');
});

// --- isAdmin / isClassicUser ----------------------------------------------

test('isAdmin reflects the given userStatus without needing the current user', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->isAdmin('admin'))
        ->toBeTrue()
        ->and($extension->isAdmin('normal'))
        ->toBeFalse();
});

test('isClassicUser reflects the given userStatus without needing the current user', function (): void {
    $extension = piwigo_extension_test_build();

    expect($extension->isClassicUser('normal'))
        ->toBeTrue()
        ->and($extension->isClassicUser('guest'))
        ->toBeFalse();
});

// --- cat -------------------------------------------------------------------

test('cat concatenates the piped value with every extra piece', function (): void {
    expect(PiwigoExtension::cat('a', 'b', 'c'))
        ->toBe('abc');
});

test('cat casts non-string scalars to string before concatenating', function (): void {
    expect(PiwigoExtension::cat(1, 2.5, true))
        ->toBe('12.51');
});

// --- stripTags / defaultFilter --------------------------------------------

test('stripTags replaces every tag with a single space by default', function (): void {
    expect(PiwigoExtension::stripTags('<b>x</b><i>y</i>'))
        ->toBe(' x  y ');
});

test('stripTags removes tags without replacement when replaceWithSpace is false', function (): void {
    expect(PiwigoExtension::stripTags('<b>x</b><i>y</i>', false))
        ->toBe('xy');
});

test('stripTags coerces a non-scalar value to an empty string first', function (): void {
    expect(PiwigoExtension::stripTags(['not', 'scalar']))
        ->toBe('');
});

test('defaultFilter returns the fallback for every empty sentinel value', function (): void {
    foreach ([null, false, 0, '0', '', []] as $sentinel) {
        expect(PiwigoExtension::defaultFilter($sentinel, 'fallback'))
            ->toBe('fallback');
    }
});

test('defaultFilter returns the original value when it is not empty', function (): void {
    expect(PiwigoExtension::defaultFilter('real value', 'fallback'))
        ->toBe('real value');
});

// --- replace / strReplace / join -------------------------------------------

test('replace does a plain scalar search/replacement, not a regex', function (): void {
    expect(PiwigoExtension::replace('a.b.c', '.', '-'))
        ->toBe('a-b-c');
});

test('strReplace reorders to str_replace($search, $replace, $subject) with the piped value as $subject', function (): void {
    expect(PiwigoExtension::strReplace('hello world', 'world', 'there'))
        ->toBe('hello there');
});

test('join reorders to implode($glue, $pieces) with the piped array first', function (): void {
    expect(PiwigoExtension::join(['a', 'b', 'c'], ', '))
        ->toBe('a, b, c');
});

test('join defaults the glue to a comma', function (): void {
    expect(PiwigoExtension::join(['a', 'b']))
        ->toBe('a,b');
});

// --- htmlOptions -----------------------------------------------------------

test('htmlOptions renders one <option> per associative entry', function (): void {
    $result = PiwigoExtension::htmlOptions(options: [
        'a' => 'Label A',
        'b' => 'Label B',
    ]);

    expect((string) $result)
        ->toBe("<option value=\"a\">Label A</option>\n<option value=\"b\">Label B</option>\n");
});

test('htmlOptions marks the selected value', function (): void {
    $result = PiwigoExtension::htmlOptions(options: [
        'a' => 'Label A',
        'b' => 'Label B',
    ], selected: 'b');

    expect((string) $result)
        ->toContain('<option value="b" selected="selected">Label B</option>');
});

test('htmlOptions wraps the result in a <select> only when name is given', function (): void {
    $bare = PiwigoExtension::htmlOptions(options: [
        'a' => 'Label A',
    ]);
    $wrapped = PiwigoExtension::htmlOptions(options: [
        'a' => 'Label A',
    ], name: 'my-select');

    expect((string) $bare)
        ->not->toContain('<select')
        ->and((string) $wrapped)
        ->toContain('<select name="my-select">');
});

test('htmlOptions double-encodes nothing -- an option label already containing a real HTML entity is not re-escaped', function (): void {
    // Real regression: Smarty's own smarty_function_escape_special_chars()
    // calls htmlspecialchars() with $double_encode=false -- see
    // escapeHtmlOption()'s own docblock (permalinks.latte's indentation
    // prefix bakes in literal &nbsp; sequences).
    $result = PiwigoExtension::htmlOptions(options: [
        'a' => '&nbsp;Indented',
    ]);

    expect((string) $result)
        ->toContain('&nbsp;Indented')
        ->not->toContain('&amp;nbsp;');
});

test('htmlOptions returns an empty Html when neither options nor values is given', function (): void {
    expect((string) PiwigoExtension::htmlOptions())
        ->toBe('');
});

// --- htmlRadios --------------------------------------------------------

test('htmlRadios renders one radio row per entry, marking the selected/checked value', function (): void {
    $result = PiwigoExtension::htmlRadios(options: [
        'a' => 'Label A',
        'b' => 'Label B',
    ], selected: 'b', name: 'my-radio');

    expect((string) $result)
        ->toContain('name="my-radio" value="a"')
        ->toContain('name="my-radio" value="b" checked="checked"');
});

test('htmlRadios returns an empty Html when neither options nor values is given', function (): void {
    expect((string) PiwigoExtension::htmlRadios())
        ->toBe('');
});
