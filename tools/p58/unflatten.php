<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * [P58 technique 1] Rewrites a template's `$bag['key']` reads to
 * `$bag->prop`, deriving the key->property map from the VO's own `toArray()`
 * body rather than from anything hand-written.
 *
 * **Fails closed.** It refuses to write unless every `$bag[...]` read in the
 * template is accounted for. Audited against double-quoted keys, a missing
 * trailing comma in `toArray()`, dynamic `$bag[$k]` reads and key-prefix
 * collisions: each leaves a residual and blocks the write.
 *
 * **Two blind spots it does not cover, by construction:**
 *
 *  - *Nested reads.* `$search['filters_views']['last_filters_conf']` becomes
 *    `$search->filtersViews['last_filters_conf']` and this reports "0
 *    unmapped, 0 residual" -- the inner access survives as an array offset on
 *    a VO. That is how a real bug reached PHPStan rather than this script.
 *  - *PHP-side reads.* A View's `exposedPageData()`/`exposedStrings()` read
 *    the same bag and must be updated by hand.
 *
 * So a clean report here is not verification. **PHPStan after the rewrite is
 * the gate.**
 *
 * Usage:
 *   php tools/p58/unflatten.php <Vo.php> <template.latte> <varName> [--apply]
 */
$root = dirname(__DIR__, 2);
chdir($root);

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

[$voPath, $templatePath, $variable] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? null];
$apply = in_array('--apply', $argv, true);

if ($voPath === null || $templatePath === null || $variable === null) {
    fwrite(STDERR, "usage: php tools/p58/unflatten.php <Vo.php> <template.latte> <varName> [--apply]\n");
    exit(1);
}
foreach ([$voPath, $templatePath] as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "not a file: {$path}\n");
        exit(1);
    }
}

$voSource = (string) file_get_contents($voPath);
if (preg_match('/public function toArray\(\).*?\n    \{(.*?)\n    \}/s', $voSource, $body) !== 1) {
    fwrite(STDERR, "no toArray() found in {$voPath}\n");
    exit(1);
}

/*
 * Three shapes, all common:
 *
 *  - keys declared in the returned array literal;
 *  - keys appended afterwards (`$result['U_DELETE'] = $this->uDelete;`),
 *    often inside an `if` -- which is itself the signal that the property is
 *    nullable and the template guards on its absence;
 *  - keys whose value is a *nested* flatten of a list of VOs,
 *    `'rates' => array_map(fn (RateRow $r) => $r->toArray(), $this->rates)`.
 *
 * The third is still a derivable key -> property pair: only the element type
 * is flattened, not which property the key comes from. It matters because
 * these nest -- rating.latte reads `$image['rates']` and then `$rate['…']`
 * inside it -- so the outer rewrite is blocked until the key maps, and the
 * inner one is a separate run against the element's own VO.
 *
 * Anything else genuinely computed stays unmapped, and the write blocks.
 */
$map = [];
preg_match_all('~\'([^\']+)\'\s*=>\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*,~', $body[1], $literal, PREG_SET_ORDER);
preg_match_all('~\$\w+\[\'([^\']+)\'\]\s*=\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*;~', $body[1], $appended, PREG_SET_ORDER);
preg_match_all(
    '~\'([^\']+)\'\s*=>\s*array_map\([^;]*?\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\)~s',
    $body[1],
    $nested,
    PREG_SET_ORDER
);
foreach ([...$literal, ...$appended, ...$nested] as $pair) {
    $map[$pair[1]] = $pair[2];
}

if ($map === []) {
    fwrite(STDERR, "no derivable 'key' => \$this->prop pairs in {$voPath}'s toArray()\n");
    exit(1);
}

$template = (string) file_get_contents($templatePath);
$quoted = preg_quote($variable, '/');
preg_match_all('/\$' . $quoted . "\['([^']+)'\]/", $template, $found);
$reads = array_values(array_unique($found[1]));

$unmapped = array_values(array_diff($reads, array_keys($map)));
$rewritten = $template;
foreach ($reads as $key) {
    if (isset($map[$key])) {
        $rewritten = str_replace("\${$variable}['{$key}']", "\${$variable}->{$map[$key]}", $rewritten);
    }
}

preg_match_all('/\$' . $quoted . '\[[^\]]*\]/', $rewritten, $residualMatches);
$residual = array_values(array_unique($residualMatches[0]));

printf(
    "%s: %d distinct keys read, %d unmapped, %d residual\n",
    $templatePath,
    count($reads),
    count($unmapped),
    count($residual)
);
if ($unmapped !== []) {
    echo '  UNMAPPED: ', implode(', ', $unmapped), "\n";
}
if ($residual !== []) {
    echo '  RESIDUAL: ', implode(', ', $residual), "\n";
}

if (! $apply) {
    echo "  (dry run; pass --apply to write)\n";
    exit($unmapped === [] && $residual === [] ? 0 : 1);
}

if ($unmapped !== [] || $residual !== []) {
    fwrite(STDERR, "  refusing to write while reads are unaccounted for\n");
    exit(1);
}

file_put_contents($templatePath, $rewritten);
echo "  written\n";
