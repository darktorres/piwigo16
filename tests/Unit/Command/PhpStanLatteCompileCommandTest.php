<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Command\PhpStanLatteCompileCommand;
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

function phpstan_latte_compile_command_test_build(Paths $paths): PhpStanLatteCompileCommand
{
    $currentConfig = CurrentConfigTestFactory::get();
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    return new PhpStanLatteCompileCommand(
        new PiwigoExtension(
            TemplateTestFactory::build(),
            LangTestFactory::get(),
            new AccessLevelChecker($currentUser, $currentConfig),
            UrlServiceTestFactory::build(),
        ),
        $paths,
    );
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-phpstan-latte-compile-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root . '/src/Piwigo', 0o777, true);
    mkdir($root . '/themes/default/template', 0o777, true);
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

test('compiles the full template tree with framework globals and rewritten filter calls', function (): void {
    file_put_contents(
        $this->root . '/themes/default/template/page.latte',
        "{\$ROOT_URL}{='Hello'|translate}\n",
    );

    $tester = new CommandTester(phpstan_latte_compile_command_test_build(Paths::fromRoot($this->root)));
    $exit = $tester->execute([]);

    expect($exit)
        ->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Compiled 1 templates');

    $outputDir = $this->root . '/' . PhpStanLatteCompileCommand::OUTPUT_DIR;
    $page = (string) file_get_contents($outputDir . '/themes-default-template-page.latte.php');
    expect($page)
        ->toContain('LatteAnalysisShims::translate(')
        ->toContain('/** @var string $ROOT_URL */')
        ->toContain('/** @var \Piwigo\Template\TemplateAdapter $pwg */');
});

test('a broken template fails the run with its real path in the output', function (): void {
    file_put_contents($this->root . '/themes/default/template/ok.latte', "fine\n");
    file_put_contents($this->root . '/themes/default/template/broken.latte', "{if \$x}never closed\n");

    $tester = new CommandTester(phpstan_latte_compile_command_test_build(Paths::fromRoot($this->root)));
    $exit = $tester->execute([]);

    expect($exit)
        ->toBe(Command::FAILURE);
    expect($tester->getDisplay())
        ->toContain('broken.latte');
});

test('prunes stale compiled outputs for templates that no longer exist', function (): void {
    file_put_contents($this->root . '/themes/default/template/keep.latte', "kept\n");
    $paths = Paths::fromRoot($this->root);

    $tester = new CommandTester(phpstan_latte_compile_command_test_build($paths));
    expect($tester->execute([]))->toBe(Command::SUCCESS);

    $outputDir = $this->root . '/' . PhpStanLatteCompileCommand::OUTPUT_DIR;
    file_put_contents($outputDir . '/themes-default-template-gone.latte.php', '<?php // stale');

    expect($tester->execute([]))->toBe(Command::SUCCESS);
    expect(file_exists($outputDir . '/themes-default-template-gone.latte.php'))->toBeFalse();
    expect(file_exists($outputDir . '/themes-default-template-keep.latte.php'))->toBeTrue();
    expect($tester->getDisplay())
        ->toContain('1 stale outputs pruned');
});

test('a second identical run rewrites nothing', function (): void {
    file_put_contents($this->root . '/themes/default/template/stable.latte', "{\$ROOT_URL}\n");
    $paths = Paths::fromRoot($this->root);

    $tester = new CommandTester(phpstan_latte_compile_command_test_build($paths));
    expect($tester->execute([]))->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('1 changed, 0 unchanged');

    expect($tester->execute([]))->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('0 changed, 1 unchanged');
});

test('surfaces scanner and extractor notices instead of swallowing them', function (): void {
    mkdir($this->root . '/src/Piwigo/Admin', 0o777, true);
    // A call site whose literal resolves nowhere -- must surface as a notice.
    file_put_contents($this->root . '/src/Piwigo/Admin/GhostRenderer.php', <<<'PHP'
        <?php
        namespace Piwigo\Admin;
        class GhostRenderer {
            public function render($template): void {
                $template->parse('does_not_exist_anywhere.latte');
            }
        }
        PHP);
    file_put_contents($this->root . '/themes/default/template/real.latte', "real\n");

    $tester = new CommandTester(phpstan_latte_compile_command_test_build(Paths::fromRoot($this->root)));
    $exit = $tester->execute([]);

    expect($exit)
        ->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain("notice: unresolvable template 'does_not_exist_anywhere.latte'");
});
