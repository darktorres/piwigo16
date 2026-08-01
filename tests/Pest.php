<?php

declare(strict_types=1);

uses()->in('tests/Unit', 'tests/Arch', 'tests/Integration', 'tests/Contract', 'tests/Browser');

/**
 * glob() with a false-safe, reindexed return -- keeps callers' types
 * honest without short-ternary fallbacks. Lives here, not in a single
 * Arch test file, because tests/Pest.php is the one file Pest always
 * loads in every worker process -- under --parallel, ParaTest splits
 * test FILES across separate worker processes, so a plain top-level
 * `function globPaths()` declared in one test file is only defined in
 * whichever worker that specific file happened to land in, leaving it
 * undefined ("Call to undefined function globPaths()") in any other
 * worker running a DIFFERENT file that also calls it (StructuralTest.php
 * and LegacyDirectoryTest.php both do).
 *
 * @return list<string>
 */
function globPaths(string $pattern): array
{
    $matches = glob($pattern);

    return $matches === false ? [] : $matches;
}
