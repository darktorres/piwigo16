<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;

/**
 * Latte extension wiring Piwigo-specific filters/functions.
 *
 * §1.2 Wave 2 starting set: only the translation pair (`|translate`,
 * `|translate_dec`). The remaining ~44 Smarty plugins from Template.php
 * (combine_script, combine_css, define_derivative, get_extent, html_head,
 * url_is_remote, …) port over in subsequent batches; gating each batch on
 * the previous one keeps blast radius small.
 */
final class PiwigoExtension extends Extension
{
    /** @return array<string, callable> */
    public function getFilters(): array
    {
        return [
            'translate' => self::translate(...),
            'translate_dec' => self::translateDec(...),
        ];
    }

    public static function translate(string $key, string|int|float|bool|null ...$args): string
    {
        return Lang::t($key, ...$args);
    }

    public static function translateDec(int $count, string $singular, string $plural): string
    {
        return Translator::get()->plural($singular, $plural, $count);
    }
}
