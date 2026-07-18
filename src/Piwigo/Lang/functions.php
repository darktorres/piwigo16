<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;

// P23 batch 8d: relocated from the deleted include/functions.inc.php --
// l10n() alone has ~710 real call sites across ~97 files, plus 6 Smarty
// registerPlugin()/modcompiler_ registrations and 2 direct .tpl call sites
// (Template.php, header.tpl x2) that call it BY NAME -- too widely used
// and too structurally exposed to retarget every caller onto a class
// method directly, same "relocate ubiquitous utilities as unchanged free
// functions" two-track precedent as get_root_url() (P23 batch 8c,
// functions_url.inc.php) and P23 batch 7's Piwigo\PluginConfig\functions.php.
// Because both functions below keep their exact global names and
// behavior, none of Template.php's registrations or the .tpl files needed
// any change.
//
// l10n()'s own body now delegates to Piwigo\Core\Lang::t() (itself a
// thin Piwigo\Lang\Translator::translate() delegate) -- the one real
// behavioral difference preserved deliberately, not dropped: the
// debug_l10n E_USER_WARNING diagnostic for missing keys, which Lang::t()
// has no equivalent for. l10n_dec() already delegated straight to
// Translator::get()->plural() before this relocation -- unchanged.
//
// Every declaration is guarded with function_exists() for the same reason
// as PluginConfig/functions.php: composer's autoload.files loads this file
// once at process start, but a class_exists()/interface_exists() probe for
// a plausible-but-nonexistent FQCN under this namespace (e.g.
// tests/Arch/StructuralTest.php's "every Piwigo\ class ... has #[\Override]"
// test, which computes Piwigo\Lang\functions from this file's own basename
// while walking every .php file under src/Piwigo/) makes composer's PSR-4
// resolver try to autoload Piwigo\Lang\functions, resolve it to this exact
// file via the PSR-4 basename match, and `include` it a second time. The
// guard makes the second pass a safe no-op instead of a fatal.

/**
 * translation function.
 * returns the corresponding value from _$lang_ if existing else the key is returned
 * if more than one parameter is provided sprintf is applied
 *
 * @param string $key
 * @return string
 */
if (! function_exists('l10n')) {
    function l10n($key)
    {
        /**
         * @var array<string, mixed>
         */
        global $lang;

        $key = is_string($key) ? $key : (string) $key;

        $debug_l10n = \Piwigo\Config\Config::debugL10n();
        if ($debug_l10n && ! isset($lang[$key]) && $key !== '') {
            trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
        }

        $args = array_slice(func_get_args(), 1);
        if ($args === []) {
            return Lang::t($key);
        }

        // vsprintf() only accepts scalars/null; a caller passing something
        // else (array/object) has no sane string representation here, so
        // it degrades to an empty placeholder instead of crashing the
        // whole translated string.
        $values = [];
        foreach ($args as $arg) {
            $values[] = is_scalar($arg) || $arg === null ? $arg : '';
        }

        return Lang::t($key, ...$values);
    }
}

/**
 * returns the printf value for strings including %d
 * returned value is concorded with decimal value (singular, plural)
 *
 * @param string $singular_key
 * @param string $plural_key
 * @param int|float|string $decimal real callers pass numeric DB-row
 *     strings here too (e.g. menubar.inc.php's $user['nb_total_images'],
 *     a raw query-result value) -- the old body's loose `>`/`==`
 *     comparisons tolerated that silently; Translator::plural() takes a
 *     strict native int, so this coerces before delegating (confirmed via
 *     a real 500: menubar_categories.tpl's compiled l10n_dec() call
 *     passed exactly such a string).
 */
if (! function_exists('l10n_dec')) {
    function l10n_dec($singular_key, $plural_key, $decimal): string
    {
        $n = is_numeric($decimal) ? (int) $decimal : 0;

        return Translator::get()->plural($singular_key, $plural_key, $n);
    }
}
