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

// Yaml::parseFile() is declared to return mixed (a malformed manifest could
// parse to a scalar/null); treat anything that isn't a mapping as empty so
// the phases[] check below reports it the same way as a missing key.
if (! is_array($manifest)) {
    $manifest = [];
}

/** @var list<string> $errors */
$errors = [];

$phases = $manifest['phases'] ?? [];
if (! is_array($phases) || $phases === []) {
    $errors[] = 'manifest has no phases[] entries';
    $phases = [];
}

/** @var array<string, list<string>> $dependsOn */
$dependsOn = [];

foreach ($phases as $phase) {
    if (! is_array($phase)) {
        $errors[] = 'phase entry is not a mapping';
        continue;
    }

    $idRaw = $phase['id'] ?? '(unknown)';
    $id = is_scalar($idRaw) ? (string) $idRaw : '(unknown)';

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
        if (is_scalar($dependency)) {
            $detectCycle((string) $dependency);
        }
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
