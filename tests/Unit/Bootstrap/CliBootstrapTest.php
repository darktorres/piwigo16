<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CliBootstrap;
use Piwigo\Core\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    Kernel::reset();
});

afterEach(function (): void {
    Kernel::reset();
});

test('config/commands.php entries resolve to registered command names', function (): void {
    $application = CliBootstrap::buildApplication();

    /** @var list<class-string<Command>> $commandClasses */
    $commandClasses = require dirname(__DIR__, 3) . '/config/commands.php';
    expect($commandClasses)->toBeArray()->not->toBe([]);

    foreach ($commandClasses as $commandClass) {
        expect(is_subclass_of($commandClass, Command::class))->toBeTrue();

        $attribute = new ReflectionClass($commandClass)->getAttributes(AsCommand::class)[0]->newInstance();
        expect($application->has($attribute->name))->toBeTrue();
    }
});

test('the built Application also exposes the Console built-in commands', function (): void {
    $application = CliBootstrap::buildApplication();

    expect($application->has('list'))->toBeTrue()
        ->and($application->has('help'))->toBeTrue();
});
