<?php

declare(strict_types=1);

/**
 * Minimal PO key reader for parity verification.
 * Returns a map of all msgid / msgid_plural values found in a PO file.
 *
 * @return array<string, true>
 */
function read_po_keys(string $poFile): array
{
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $loader = new \Gettext\Loader\PoLoader();
    $translations = $loader->loadFile($poFile);
    $keys = [];
    foreach ($translations as $t) {
        $orig = $t->getOriginal();
        if ($orig !== '') {
            $keys[$orig] = true;
        }
        $plural = $t->getPlural();
        if ($plural !== null && $plural !== '') {
            $keys[$plural] = true;
        }
    }
    return $keys;
}
