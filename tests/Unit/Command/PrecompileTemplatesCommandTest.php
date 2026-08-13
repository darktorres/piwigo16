<?php

declare(strict_types=1);

use Piwigo\Command\PrecompileTemplatesCommand;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function precompile_templates_command_test_build(Paths $paths): PrecompileTemplatesCommand
{
    return new PrecompileTemplatesCommand(TemplateTestFactory::build(), $paths);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-precompile-templates-command-test-' . bin2hex(random_bytes(8));
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

test('compiles every real .latte file found across both themes/ and template-extension/', function (): void {
    mkdir($this->root . '/themes/admin', 0o777, true);
    file_put_contents($this->root . '/themes/admin/one.latte', "{='Hello'|translate}\n");
    mkdir($this->root . '/themes/admin/sub', 0o777, true);
    file_put_contents($this->root . '/themes/admin/sub/two.latte', "<p>plain</p>\n");
    mkdir($this->root . '/template-extension/distributed', 0o777, true);
    file_put_contents($this->root . '/template-extension/distributed/three.latte', "{if true}yes{/if}\n");
    // Non-.latte files must be ignored, not counted or attempted.
    file_put_contents($this->root . '/themes/admin/ignored.php', '<?php // not a template');

    $command = precompile_templates_command_test_build(Paths::fromRoot($this->root));
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())
        ->toContain('Compiled successfully: 3 templates.');
});

test('fails with a non-zero exit and reports the offending file on a genuine syntax error', function (): void {
    mkdir($this->root . '/themes/admin', 0o777, true);
    file_put_contents($this->root . '/themes/admin/broken.latte', "{if \$x}\nunclosed\n");

    $command = precompile_templates_command_test_build(Paths::fromRoot($this->root));
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(Command::FAILURE)
        ->and($tester->getDisplay())
        ->toContain('broken.latte')
        ->toContain('Failed: 1, succeeded: 0');
});

test('a real syntax error in one file does not stop the rest from compiling', function (): void {
    mkdir($this->root . '/themes/admin', 0o777, true);
    file_put_contents($this->root . '/themes/admin/broken.latte', "{if \$x}\nunclosed\n");
    file_put_contents($this->root . '/themes/admin/valid.latte', "<p>fine</p>\n");

    $command = precompile_templates_command_test_build(Paths::fromRoot($this->root));
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(Command::FAILURE)
        ->and($tester->getDisplay())
        ->toContain('Failed: 1, succeeded: 1');
});

test('reports zero compiled with no failures when neither directory exists', function (): void {
    $command = precompile_templates_command_test_build(Paths::fromRoot($this->root));
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())
        ->toContain('Compiled successfully: 0 templates.');
});
