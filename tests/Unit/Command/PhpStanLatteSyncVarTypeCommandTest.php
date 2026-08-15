<?php

declare(strict_types=1);

use Piwigo\Command\PhpStanLatteSyncVarTypeCommand;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-phpstan-latte-sync-vartype-test-' . bin2hex(random_bytes(8));
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

test('check mode fails and leaves the file untouched when a block is missing', function (): void {
    file_put_contents($this->root . '/themes/default/template/page.latte', "{\$ROOT_URL}\n");

    $tester = new CommandTester(new PhpStanLatteSyncVarTypeCommand(Paths::fromRoot($this->root)));
    $exit = $tester->execute([]);

    expect($exit)
        ->toBe(Command::FAILURE);
    expect($tester->getDisplay())
        ->toContain('page.latte')
        ->toContain('needs its {varType} block updated');
    expect((string) file_get_contents($this->root . '/themes/default/template/page.latte'))
        ->toBe("{\$ROOT_URL}\n");
});

test('--fix writes the block and a second run is clean', function (): void {
    file_put_contents($this->root . '/themes/default/template/page.latte', "{\$ROOT_URL}\n");

    $tester = new CommandTester(new PhpStanLatteSyncVarTypeCommand(Paths::fromRoot($this->root)));
    $fixExit = $tester->execute([
        '--fix' => true,
    ]);

    expect($fixExit)
        ->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('1 changed, 0 unchanged');

    $written = (string) file_get_contents($this->root . '/themes/default/template/page.latte');
    expect($written)
        ->toContain('{* BEGIN varType')
        ->toContain('{varType string $ROOT_URL}')
        ->toContain('{* END varType *}')
        ->toContain('{$ROOT_URL}');

    $checkExit = $tester->execute([]);
    expect($checkExit)
        ->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('0 changed, 1 unchanged');
});

test('surfaces scanner and extractor notices instead of swallowing them', function (): void {
    mkdir($this->root . '/src/Piwigo/Admin', 0o777, true);
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

    $tester = new CommandTester(new PhpStanLatteSyncVarTypeCommand(Paths::fromRoot($this->root)));
    $tester->execute([
        '--fix' => true,
    ]);

    expect($tester->getDisplay())
        ->toContain("notice: unresolvable template 'does_not_exist_anywhere.latte'");
});
