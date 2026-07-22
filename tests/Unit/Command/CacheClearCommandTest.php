<?php

declare(strict_types=1);

use Piwigo\Command\CacheClearCommand;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

// Latte cache dir path is hardcoded relative to the source file (same
// convention as CacheFactory's own _data/cache/ path), so this exercises
// the real project _data/templates_c/latte/ dir rather than an injected
// fake -- matching CacheFactoryTest's own precedent of touching the real
// relative _data/ tree.
$latteDir = dirname(__DIR__, 3) . '/_data/templates_c/latte';

afterEach(function () use ($latteDir): void {
    if (is_dir($latteDir)) {
        exec('rm -rf ' . escapeshellarg($latteDir));
    }
});

test('removes an existing Latte compiled-template cache dir', function () use ($latteDir): void {
    mkdir($latteDir, 0o775, true);
    file_put_contents($latteDir . '/some_compiled.php', '<?php // fixture');

    $command = new CacheClearCommand(new ArrayAdapter());
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($latteDir))->toBeFalse()
        ->and($tester->getDisplay())->toContain('Removed Latte compiled-template cache');
});

test('reports an already-empty Latte cache dir without erroring', function () use ($latteDir): void {
    if (is_dir($latteDir)) {
        exec('rm -rf ' . escapeshellarg($latteDir));
    }

    $command = new CacheClearCommand(new ArrayAdapter());
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0)
        ->and($tester->getDisplay())->toContain('already empty');
});

test('clears the injected PSR-6 cache pool', function (): void {
    $pool = new ArrayAdapter();
    $item = $pool->getItem('some-key');
    $item->set('some-value');
    $pool->save($item);

    expect($pool->getItem('some-key')->isHit())->toBeTrue();

    $command = new CacheClearCommand($pool);
    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($pool->getItem('some-key')->isHit())->toBeFalse();
});
