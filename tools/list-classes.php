<?php

declare(strict_types=1);
// tools/list-classes.php — one-shot inventory generator
// Usage: php tools/list-classes.php > docs/class-inventory.csv

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$root = dirname(__DIR__);
$globs = [
    $root . '/include/*.inc.php',
    $root . '/include/ws_protocols/*.php',
    $root . '/admin/include/*.inc.php',
];

$files = [];
foreach ($globs as $g) {
    foreach (glob($g) ?: [] as $f) {
        $files[] = $f;
    }
}

$parser = (new ParserFactory())->createForHostVersion();

final class ClassCollector extends NodeVisitorAbstract
{
    /** @var list<array{name:string,extends:string,abstract:bool,final:bool,interface:bool}> */
    public array $classes = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $this->classes[] = [
                'name'      => $node->name->toString(),
                'extends'   => $node->extends?->toString() ?? '',
                'abstract'  => $node->isAbstract(),
                'final'     => $node->isFinal(),
                'interface' => false,
            ];
        }
        if ($node instanceof Node\Stmt\Interface_ && $node->name !== null) {
            $this->classes[] = [
                'name'      => $node->name->toString(),
                'extends'   => implode(',', array_map(fn ($e) => $e->toString(), $node->extends)),
                'abstract'  => false,
                'final'     => false,
                'interface' => true,
            ];
        }
        return null;
    }
}

$entries = [];

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Throwable) {
        continue;
    }
    if ($ast === null) {
        continue;
    }
    $vis  = new ClassCollector();
    $trav = new NodeTraverser();
    $trav->addVisitor($vis);
    $trav->traverse($ast);
    foreach ($vis->classes as $c) {
        $entries[] = ['file' => $file, 'class' => $c];
    }
}

// Reference scan: find files that reference each class by string literal.
$searchRoots = [
    $root . '/include',
    $root . '/admin',
    $root . '/install',
    $root . '/themes/_base',
];
$haystack = [];
foreach ($searchRoots as $sr) {
    if (!is_dir($sr)) {
        continue;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sr, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.php')) {
            continue;
        }
        if (str_contains($p, DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (str_contains($p, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $body = file_get_contents($p);
        if ($body !== false) {
            $haystack[$p] = $body;
        }
    }
}
// Also scan root entry points
foreach (glob($root . '/*.php') ?: [] as $ep) {
    $body = file_get_contents($ep);
    if ($body !== false) {
        $haystack[$ep] = $body;
    }
}

foreach ($entries as $i => $row) {
    $name    = $row['class']['name'];
    $needles = ["'$name'", "\"$name\"", "'$name'", "\"$name\""];
    $hits    = [];
    foreach ($haystack as $path => $body) {
        foreach ($needles as $n) {
            if (str_contains($body, $n)) {
                $hits[] = $path;
                break;
            }
        }
    }
    sort($hits);
    $entries[$i]['refs'] = array_unique($hits);
}

// Emit CSV
$out = fopen('php://stdout', 'w');
fputcsv($out, ['current_path', 'class_name', 'extends', 'abstract', 'final', 'interface', 'referenced_by_string_in_files'], escape: '\\');
foreach ($entries as $row) {
    [$path, $cls] = [$row['file'], $row['class']];
    fputcsv($out, [
        str_replace($root . DIRECTORY_SEPARATOR, '', $path),
        $cls['name'],
        $cls['extends'],
        $cls['abstract'] ? '1' : '0',
        $cls['final'] ? '1' : '0',
        $cls['interface'] ? '1' : '0',
        implode('|', array_map(fn ($p) => str_replace($root . DIRECTORY_SEPARATOR, '', $p), $row['refs'])),
    ], escape: '\\');
}
fclose($out);
