<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal plan-lint (docs/PLAN-REPLAY.md P0 hard gate): every phase must declare
 * a tier and a depends_on list, and the depends_on graph must be acyclic.
 *
 * Full SEC-coverage cross-checking (prose checklist <-> manifest, verified_by
 * presence) is added once more phases exist to exercise it - not overbuilt here.
 */
$manifestPath = __DIR__ . '/../docs/plan/manifest.yaml';
$manifest = Yaml::parseFile($manifestPath);

/** @var list<string> $errors */
$errors = [];

$phases = $manifest['phases'] ?? [];
if (! is_array($phases) || $phases === []) {
    $errors[] = 'manifest has no phases[] entries';
}

/** @var array<string, list<string>> $dependsOn */
$dependsOn = [];

foreach ($phases as $phase) {
    $id = $phase['id'] ?? '(unknown)';

    if (! isset($phase['tier']) || $phase['tier'] === '') {
        $errors[] = "phase {$id} is missing tier";
    }

    if (! array_key_exists('depends_on', $phase) || ! is_array($phase['depends_on'])) {
        $errors[] = "phase {$id} is missing depends_on";
        continue;
    }

    $dependsOn[$id] = $phase['depends_on'];
}

// Cycle detection: DFS with a recursion-stack marker per node.
$visited = [];
$onStack = [];

$detectCycle = static function (string $node) use (&$detectCycle, &$visited, &$onStack, $dependsOn, &$errors): void {
    if (isset($onStack[$node])) {
        $errors[] = "depends_on graph is cyclic at {$node}";

        return;
    }

    if (isset($visited[$node])) {
        return;
    }

    $visited[$node] = true;
    $onStack[$node] = true;

    foreach ($dependsOn[$node] ?? [] as $dependency) {
        $detectCycle((string) $dependency);
    }

    unset($onStack[$node]);
};

foreach (array_keys($dependsOn) as $id) {
    $detectCycle($id);
}

if ($errors !== []) {
    fwrite(STDERR, "plan-lint: FAILED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

echo 'plan-lint: OK (' . count($phases) . " phases checked)\n";
