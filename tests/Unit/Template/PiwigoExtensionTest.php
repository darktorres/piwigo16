<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\DateHelper;
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
 * `getCombinedScripts`/`getCombinedCss`/`getPageDataScript`/
 * `defineDerivative`/`localCssRules`/`once` are deliberately NOT
 * retested here -- they're thin `$this->template->x(...)` delegates
 * with their own real coverage in TemplateInstanceTest.php; duplicating
 * it against a second, PiwigoExtension-constructed Template would just
 * be the same assertions with extra indirection.
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

// --- replace / strReplace ---------------------------------------------

test('replace does a plain scalar search/replacement, not a regex', function (): void {
    expect(PiwigoExtension::replace('a.b.c', '.', '-'))
        ->toBe('a-b-c');
});

test('strReplace reorders to str_replace($search, $replace, $subject) with the piped value as $subject', function (): void {
    expect(PiwigoExtension::strReplace('hello world', 'world', 'there'))
        ->toBe('hello there');
});

// --- closeTags -----------------------------------------------------------

test('closeTags repeats the piped tag fragment count times, wrapped as trusted Html', function (): void {
    $result = PiwigoExtension::closeTags('</ul></li>', 3);

    expect((string) $result)
        ->toBe('</ul></li></ul></li></ul></li>');
});

test('closeTags returns an empty Html for a zero count', function (): void {
    $result = PiwigoExtension::closeTags('</ul></li>', 0);

    expect((string) $result)
        ->toBe('');
});

// --- rawHtml ---------------------------------------------------------------

test('rawHtml wraps a literal template-authored fragment as trusted Html', function (): void {
    $result = PiwigoExtension::rawHtml('<li class="selected">');

    expect((string) $result)
        ->toBe('<li class="selected">');
});

/**
 * P58 step 2. The date filters exist so a row VO can carry the domain value
 * and let the template format it, instead of a producer baking a localized
 * string into a display key beside the row's real data.
 *
 * The requirement is byte-invariance: the filter must produce exactly what
 * the producers' own DateHelper call produces today, or every golden fixture
 * moves. These assert against DateHelper directly rather than against a
 * hardcoded string, so they stay correct under a different test locale and
 * whether or not ext-intl is installed -- DateHelper resolves its own
 * language and IntlDateFormatter availability inside the call.
 */
test('the format_date filter is registered and reproduces DateHelper::formatDate exactly', function (): void {
    $filters = piwigo_extension_test_build()
        ->getFilters();

    expect($filters)
        ->toHaveKey('format_date');
    $filter = $filters['format_date'];

    expect($filter('2024-06-15 12:34:56'))
        ->toBe(DateHelper::formatDate('2024-06-15 12:34:56'));
    // The $show array is the shape the producers actually pass.
    expect($filter('2024-06-15 12:34:56', ['day', 'month', 'year']))
        ->toBe(DateHelper::formatDate('2024-06-15 12:34:56', ['day', 'month', 'year']));
    // And the widened input type, which is the point of carrying the value
    // on the VO rather than a preformatted string.
    $immutable = new DateTimeImmutable('2024-06-15 12:34:56');
    expect($filter($immutable))
        ->toBe(DateHelper::formatDate($immutable));
});

test('the time_since filter is registered and reproduces DateHelper::timeSince exactly', function (): void {
    $filters = piwigo_extension_test_build()
        ->getFilters();

    expect($filters)
        ->toHaveKey('time_since');
    $filter = $filters['time_since'];

    expect($filter('2024-06-15 12:34:56'))
        ->toBe(DateHelper::timeSince('2024-06-15 12:34:56'));
    // $stop is the second argument at every producer call site that passes one.
    expect($filter('2024-06-15 12:34:56', 'day'))
        ->toBe(DateHelper::timeSince('2024-06-15 12:34:56', 'day'));
});

/**
 * `|json_encode` used to be a bare `json_encode(...)` delegate returning
 * `string|false`, and three call sites pipe it straight into
 * `|htmlspecialchars`, which takes a `string`. The `false` would have
 * reached those as an empty attribute for the browser to `JSON.parse('')`
 * on -- an encoding failure surfacing as a JavaScript error a layer away.
 */
test('jsonEncode returns a string for the values the templates actually pipe through it', function (): void {
    expect(PiwigoExtension::jsonEncode(5))->toBe('5');
    expect(PiwigoExtension::jsonEncode(null))->toBe('null');
    expect(PiwigoExtension::jsonEncode([[
        'name' => 'Holidays',
        'id' => '3',
    ]]))
        ->toBe('[{"name":"Holidays","id":"3"}]');
});

test('jsonEncode throws rather than returning false when a value cannot be encoded', function (): void {
    // Invalid UTF-8: json_encode()'s own documented failure case, and the
    // one that used to yield false.
    expect(static fn (): string => PiwigoExtension::jsonEncode("\xB1\x31"))
        ->toThrow(JsonException::class);
});
