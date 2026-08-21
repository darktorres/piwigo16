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
 * assertions with extra indirection.
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

// --- defaultFilter -----------------------------------------------------

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

// --- replace / strReplace ---------------------------------------------

test('replace does a plain scalar search/replacement, not a regex', function (): void {
    expect(PiwigoExtension::replace('a.b.c', '.', '-'))
        ->toBe('a-b-c');
});

test('strReplace reorders to str_replace($search, $replace, $subject) with the piped value as $subject', function (): void {
    expect(PiwigoExtension::strReplace('hello world', 'world', 'there'))
        ->toBe('hello there');
});
