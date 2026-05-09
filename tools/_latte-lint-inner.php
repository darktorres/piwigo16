<?php

declare(strict_types=1);

/**
 * Inner half of tools/latte-lint.php — invoked as a subprocess so its
 * STDERR (where Latte's Linter writes [WARNING] markers) can be captured.
 * Don't run this script directly; use `composer lint:latte` or
 * `php tools/latte-lint.php`.
 */

require __DIR__ . '/../vendor/autoload.php';

use Latte\Engine;
use Latte\Feature;
use Latte\Tools\Linter;
use Latte\Tools\LinterExtension;
use Piwigo\Template\Latte\PiwigoExtension;

/** @var list<string> $argv */
global $argv;
$paths = array_slice($argv, 1);
if ($paths === []) {
    fwrite(STDERR, "Inner linter expects at least one path\n");
    exit(2);
}

$engine = new Engine();
$engine->setFeature(Feature::StrictTypes);
$engine->addExtension(new PiwigoExtension());
// Bundled validator: warns on unknown filters/functions/classes/methods.
// Required because we pass our own engine — Linter::createEngine() (the
// bundled engine builder, which adds LinterExtension by default) is
// bypassed when the constructor receives an engine.
$engine->addExtension(new LinterExtension());

$linter = new Linter(engine: $engine, strict: true);

$ok = true;
foreach ($paths as $path) {
    if (!is_dir($path) && !is_file($path)) {
        echo "Skip: $path (not found)\n";
        continue;
    }
    $ok = $linter->scanDirectory($path) && $ok;
}

exit($ok ? 0 : 1);
