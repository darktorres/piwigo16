<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Core\Kernel;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    Kernel::reset();
});

afterEach(function (): void {
    Kernel::reset();
});

test('config/commands.php entries resolve to registered command names', function (): void {
    $application = CliBootstrap::buildApplication();

    $commandClasses = require dirname(__DIR__, 3) . '/config/commands.php';
    expect($commandClasses)->toBeArray()->not->toBe([]);

    foreach ($commandClasses as $commandClass) {
        expect(is_subclass_of($commandClass, Command::class))->toBeTrue();

        $name = (new ReflectionClass($commandClass))->getAttributes()[0]->newInstance()->name;
        expect($application->has($name))->toBeTrue();
    }
});

test('the built Application also exposes the Console built-in commands', function (): void {
    $application = CliBootstrap::buildApplication();

    expect($application->has('list'))->toBeTrue()
        ->and($application->has('help'))->toBeTrue();
});
