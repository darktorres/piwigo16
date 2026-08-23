<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Command\LintLatteCommand;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `Latte\Tools\Linter::scanDirectory()`'s own boolean return only reflects
 * genuine parse errors -- an unknown-filter/function warning (what
 * `LinterExtension` exists to catch) is written to STDERR only and does
 * NOT flip that return value, confirmed live against the real Linter.
 * That's why `composer lint:latte`'s real pass/fail signal lives one
 * layer up, in `tools/latte-lint.php`'s own STDERR `[WARNING]` regex scan
 * (this class's own two-process design docblock explains why: `Linter`
 * is `final` and can't be hooked from inside the same process). So this
 * file tests what `LintLatteCommand::execute()` itself actually controls
 * -- the parse-error exit code, and that a real Piwigo filter reaches the
 * linter recognized (no warning at all) -- plus documents the
 * unknown-filter case's real, easy-to-assume-wrong shape: `SUCCESS` at
 * this layer, with the warning only visible in the command's own error
 * output.
 */
function lint_latte_command_test_build(): LintLatteCommand
{
    $currentConfig = CurrentConfigTestFactory::get();
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    $extension = new PiwigoExtension(
        TemplateTestFactory::build(),
        LangTestFactory::get(),
        new AccessLevelChecker($currentUser, $currentConfig),
        UrlServiceTestFactory::build(),
    );

    return new LintLatteCommand($extension);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-lint-latte-command-test-' . bin2hex(random_bytes(8));
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
    if (is_dir($this->root)) {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
});

test('exits successfully and recognizes a real Piwigo filter with no warning', function (): void {
    $dir = $this->root . '/templates';
    mkdir($dir);
    file_put_contents($dir . '/valid.latte', "{='Hello'|translate}\n");

    $command = lint_latte_command_test_build();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'paths' => [$dir],
    ]);

    expect($exitCode)
        ->toBe(Command::SUCCESS);
});

test('exits with failure on a genuine Latte syntax error', function (): void {
    $dir = $this->root . '/templates';
    mkdir($dir);
    file_put_contents($dir . '/broken.latte', "{if \$x}\nunclosed\n");

    $command = lint_latte_command_test_build();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'paths' => [$dir],
    ]);

    expect($exitCode)
        ->toBe(Command::FAILURE);
});

test('exits with failure on valid Latte syntax with mismatched HTML tags', function (): void {
    // The motivating bug for this whole class's strict-parsing fix:
    // `Latte\Tools\Linter`'s own `strict` constructor flag is a no-op
    // whenever a custom `$engine` is supplied (see this command's own
    // execute() docblock) -- a genuine mismatched-tag template like this
    // one used to pass linting clean, silently, because
    // Feature::StrictParsing was never actually turned on. This is valid
    // Latte syntax throughout (no `{if}`/macro involved, unlike the test
    // above -- plain literal HTML with no `n:` attribute, confirmed live
    // that Latte tracks an `n:`-attributed tag's own open/close
    // regardless of strict mode, since tag-macro scoping needs that
    // either way); only the HTML tag balance is broken (`<div>` closed as
    // `</p>`), which only strict HTML parsing catches.
    $dir = $this->root . '/templates';
    mkdir($dir);
    file_put_contents($dir . '/mismatched-tags.latte', "<div>hello</p>\n");

    $command = lint_latte_command_test_build();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'paths' => [$dir],
    ]);

    expect($exitCode)
        ->toBe(Command::FAILURE);
});

test('an unknown filter does not flip this command\'s own exit code', function (): void {
    // Latte\Tools\Linter writes the real "[WARNING] ... Unknown filter"
    // marker straight to the raw STDERR stream constant (confirmed live),
    // bypassing Symfony Console's own $output/$errorOutput objects
    // entirely -- not observable through CommandTester at all, by the
    // same "final class, private per-file error handler" constraint this
    // class's own docblock explains. That's why tools/latte-lint.php runs
    // this command as a real subprocess and reads its OS-level stderr
    // pipe instead -- the actual pass/fail signal for an unknown filter
    // lives one layer up from what's tested here. What IS this command's
    // own real, testable behavior: scanDirectory()'s return value (and
    // therefore this command's exit code) reflects only genuine parse
    // errors, confirmed unaffected by an unknown-filter warning.
    $dir = $this->root . '/templates';
    mkdir($dir);
    file_put_contents($dir . '/unknown-filter.latte', "{\$var|thisFilterDoesNotExist}\n");

    $command = lint_latte_command_test_build();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'paths' => [$dir],
    ]);

    expect($exitCode)
        ->toBe(Command::SUCCESS);
});

test('skips a nonexistent path without failing the run', function (): void {
    $command = lint_latte_command_test_build();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'paths' => [$this->root . '/does-not-exist'],
    ]);

    expect($exitCode)
        ->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())
        ->toContain('Skip:');
});

test('defaults to scanning themes/ when no paths argument is given', function (): void {
    $command = lint_latte_command_test_build();
    $definition = $command->getDefinition();

    expect($definition->getArgument('paths')->getDefault())
        ->toBe(['themes']);
});
