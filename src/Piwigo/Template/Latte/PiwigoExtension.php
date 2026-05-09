<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Lang\Translator;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

/**
 * Latte extension wiring Piwigo-specific filters/functions.
 *
 * §1.2 Wave 2 Phase B.1 + B.2: stateless port. Covers translate +
 * translate_dec (Phase A), the ~25 PHP-passthrough modifiers Smarty's
 * Template.php registered via `registerPlugin('modifier', name, fn)`,
 * and the 8 small custom modifiers (l10n alias, explode, ternary,
 * url_is_remote, is_admin, is_classic_user, get_device,
 * get_gallery_home_url).
 *
 * Out of scope for this commit: the asset functions (combine_script,
 * combine_css, define_derivative, get_combined_scripts,
 * get_combined_css, html_head, html_style, footer_script) and
 * get_extent — they touch Template's internal state (script/css
 * loaders, html_head_elements buffer, extension hook map) so they need
 * the state extracted into a service before the port can be stateless.
 * That's Phase B.3.
 */
final class PiwigoExtension extends Extension
{
    /** @return array<string, callable> */
    public function getFilters(): array
    {
        return [
            // Phase A — translation pair.
            'translate' => self::translate(...),
            'translate_dec' => self::translateDec(...),

            // Phase B.1 — PHP passthroughs whose first argument matches
            // Smarty's "pipe value goes first" convention. Templates that
            // pipe `{$x|sprintf:'%d',$n}` keep working without a rename
            // after the Smarty → Latte conversion.
            //
            // Pipe-broken on PHP 8+ are deliberately omitted: implode
            // (PHP wants $glue,$array — Smarty pipes $array first),
            // str_replace / str_ireplace / preg_match (PHP wants
            // $search/$pattern first), strstr/stristr where the haystack
            // matches the pipe value but argument-only PHP signatures
            // differ. Verified against `themes/ plugins/`: the only
            // in-the-wild pipe use among these is `|in_array` (1 site,
            // PHP signature happens to match Smarty's), so the rest can
            // stay un-registered. Templates that need them in Latte call
            // the underlying function in an expression: `{=implode(',',
            // $arr)}` rather than `{$arr|implode:','}`.
            'sprintf' => sprintf(...),
            'urlencode' => urlencode(...),
            'intval' => intval(...),
            'file_exists' => file_exists(...),
            'constant' => constant(...),
            'json_encode' => json_encode(...),
            'json_decode' => json_decode(...),
            'htmlspecialchars' => htmlspecialchars(...),
            'stripslashes' => stripslashes(...),
            'in_array' => in_array(...),
            'ucfirst' => ucfirst(...),
            'trim' => trim(...),
            'md5' => md5(...),
            'strtolower' => strtolower(...),
            'is_null' => is_null(...),
            'is_file' => is_file(...),
            'strpos' => strpos(...),
            'sizeOf' => sizeof(...),

            // Phase B.2 — small custom modifiers. Each is a one-liner
            // delegating to an existing service.
            'l10n' => self::translate(...),
            'explode' => self::explode(...),
            'ternary' => self::ternary(...),
            'url_is_remote' => UrlService::urlIsRemote(...),
            'is_admin' => self::isAdmin(...),
            'is_classic_user' => self::isClassicUser(...),
            'get_device' => self::getDevice(...),
            'get_gallery_home_url' => self::getGalleryHomeUrl(...),
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

    /**
     * Behaves like Template::modExplode — falls back to ',' on empty
     * delimiter, which `explode('', $s)` would otherwise reject.
     *
     * @return list<string>
     */
    public static function explode(string $text, string $delimiter = ','): array
    {
        return explode($delimiter !== '' ? $delimiter : ',', $text);
    }

    public static function ternary(mixed $param, mixed $true, mixed $false): mixed
    {
        return $param ? $true : $false;
    }

    public static function isAdmin(string $userStatus = ''): bool
    {
        return ServiceLocator::get(PermissionService::class)->isAdmin($userStatus);
    }

    public static function isClassicUser(string $userStatus = ''): bool
    {
        return ServiceLocator::get(PermissionService::class)->isClassicUser($userStatus);
    }

    public static function getDevice(): string
    {
        return Util::get()->getDevice();
    }

    public static function getGalleryHomeUrl(): string
    {
        return ServiceLocator::get(UrlGenerator::class)->gallery();
    }
}
