<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\Combinable;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
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

            // Phase B.3 — get_extent is registered as a Smarty modifier
            // (`{$filename|get_extent:$handle}`), so it stays a filter
            // here even though the rest of B.3 is functions.
            'get_extent' => self::getExtent(...),
        ];
    }

    /**
     * Latte function tags. Phase B.3: stateful asset-pipeline functions
     * that delegate to the active Template instance via TemplateRegistry.
     * Both engines share the same loaders so a Latte template's
     * `combineScript` accumulates into the same bundle a Smarty
     * template's `{combine_script}` would.
     *
     * @return array<string, callable>
     */
    public function getFunctions(): array
    {
        return [
            'combineScript' => self::combineScript(...),
            'getCombinedScripts' => self::getCombinedScripts(...),
            'combineCss' => self::combineCss(...),
            'getCombinedCss' => self::getCombinedCss(...),
            'defineDerivative' => self::defineDerivative(...),
            'htmlHead' => self::htmlHead(...),
            'htmlStyle' => self::htmlStyle(...),
            'footerScript' => self::footerScript(...),
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

    /**
     * Mirrors Template::getExtent: when a plugin has registered an
     * extension override for `$handle`, return its absolute path;
     * otherwise return the original filename.
     */
    public static function getExtent(string $filename = '', string $handle = ''): string
    {
        $tpl = TemplateRegistry::current();
        $override = $tpl->extents[$handle] ?? null;
        return is_string($override) ? $override : $filename;
    }

    // ---- Phase B.3: stateful asset-pipeline functions --------------------

    /**
     * @param list<string>|string $require comma-separated string from the
     *     converter or list<string> from a hand-written Latte template.
     */
    public static function combineScript(
        string $id,
        string $load = 'header',
        ?string $path = null,
        array|string $require = [],
        string|int $version = 0,
        bool $template = false,
    ): void {
        $loadMode = match ($load) {
            'header' => 0,
            'footer' => 1,
            'async' => 2,
            default => throw new \ValueError("combineScript: invalid 'load' parameter: $load"),
        };
        $requireList = is_string($require)
            ? ($require === '' ? [] : explode(',', $require))
            : $require;

        TemplateRegistry::current()->scriptLoader->add(
            $id,
            $loadMode,
            $requireList,
            $path,
            $version,
            $template,
        );
    }

    /**
     * Returns the marker the ScriptLoader rewrites to <script> tags at
     * flush time for the header pass; for the footer pass, returns the
     * already-serialised <script> markup. Mirrors Template::funcGetCombinedScripts.
     */
    public static function getCombinedScripts(string $load = 'header'): string
    {
        if ($load === 'header') {
            return Template::COMBINED_SCRIPTS_TAG;
        }

        $tpl = TemplateRegistry::current();
        $scripts = $tpl->scriptLoader->getFooterScripts();
        $content = [];

        foreach ($scripts[0] as $script) {
            $src = self::makeScriptSrc($script);
            $type = self::isModuleScript($script) ? 'module' : 'text/javascript';
            $content[] = '<script type="' . $type . '" src="' . $src . '"></script>';
        }

        if (count($tpl->scriptLoader->inline_scripts) > 0) {
            $content[] = '<script type="module">';
            foreach ($tpl->scriptLoader->inline_scripts as $inline) {
                $content[] = $inline;
            }
            $content[] = '</script>';
        }

        if (count($scripts[1]) > 0) {
            $content[] = '<script type="text/javascript">';
            $content[] = "(function() {\nvar s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];";
            foreach ($scripts[1] as $script) {
                $src = self::makeScriptSrc($script);
                $stype = self::isModuleScript($script) ? 'module' : 'text/javascript';
                $content[] = "s=document.createElement('script'); s.type='{$stype}'; s.async=true; s.src='{$src}';";
                $content[] = 'after = after.parentNode.insertBefore(s, after);';
            }
            $content[] = '})();';
            $content[] = '</script>';
        }

        return implode("\n", $content);
    }

    private static function isModuleScript(Combinable $script): bool
    {
        return str_starts_with($script->path, 'dist/');
    }

    private static function makeScriptSrc(Combinable $script): string
    {
        if ($script->isRemote()) {
            $src = $script->path;
        } else {
            $src = UrlService::getRootUrl() . $script->path;
            $src .= '?v' . ($script->version !== 0 && $script->version !== '' ? $script->version : AppInfo::VERSION);
        }
        // The `combined_script` event contract is "first arg is the URL,
        // mutated in place"; PHPStan's TriggerChangeDynamicReturnType
        // extension narrows dispatch's return to the first-arg type.
        $src = EventDispatcher::dispatch('combined_script', $src, $script);
        $embellished = UrlService::embellishUrl($src);
        // embellishUrl returns string|array, but is keyed by input shape
        // (string→string, array→array). We always pass a string here.
        return is_array($embellished) ? $src : $embellished;
    }

    public static function combineCss(
        string $path,
        ?string $id = null,
        string|int $version = 0,
        int $order = 0,
        bool $template = false,
    ): void {
        TemplateRegistry::current()->cssLoader->add(
            $id ?? md5($path),
            $path,
            $version,
            $order,
            $template,
        );
    }

    public static function getCombinedCss(): string
    {
        return Template::COMBINED_CSS_TAG;
    }

    /**
     * Returns the configured DerivativeParams. Smarty's
     * {define_derivative name=foo type=thumb} would assign $foo via the
     * engine; in Latte the caller does {var $foo = defineDerivative(type:
     * 'thumb')}.
     */
    public static function defineDerivative(
        ?string $type = null,
        ?int $width = null,
        ?int $height = null,
        bool|int|float|string $crop = false,
        ?int $minWidth = null,
        ?int $minHeight = null,
    ): DerivativeParams {
        if ($type !== null) {
            return ImageStdParams::getByType($type);
        }
        if ($width === null || $height === null) {
            throw new \InvalidArgumentException('defineDerivative requires either type, or width and height');
        }
        if (is_bool($crop)) {
            $cropFraction = $crop ? 1 : 0;
        } else {
            $cropFraction = round((float) $crop / 100.0, 2);
        }
        $effMinW = $cropFraction !== 0 ? ($minWidth ?? $width) : null;
        $effMinH = $cropFraction !== 0 ? ($minHeight ?? $height) : null;
        if ($effMinW !== null && $effMinW > $width) {
            throw new \InvalidArgumentException('defineDerivative: min_width > width');
        }
        if ($effMinH !== null && $effMinH > $height) {
            throw new \InvalidArgumentException('defineDerivative: min_height > height');
        }
        return ImageStdParams::getCustom($width, $height, $cropFraction, $effMinW, $effMinH);
    }

    public static function htmlHead(string $content): void
    {
        $trimmed = trim($content);
        if ($trimmed !== '') {
            TemplateRegistry::current()->html_head_elements[] = $trimmed;
        }
    }

    public static function htmlStyle(string $content): void
    {
        $trimmed = trim($content);
        if ($trimmed !== '') {
            $tpl = TemplateRegistry::current();
            // html_style is private on Template; the field's only mutator is
            // this exact pattern (Smarty's blockHtmlStyle), so expose via
            // public method on Template would be cleaner long-term — wired
            // through a getter for now.
            $tpl->appendHtmlStyle($trimmed);
        }
    }

    /**
     * @param list<string>|string $require comma-separated string from the
     *     converter or list<string> from a hand-written Latte template.
     */
    public static function footerScript(string $content, array|string $require = []): void
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return;
        }
        $requireList = is_string($require)
            ? ($require === '' ? [] : explode(',', $require))
            : $require;
        TemplateRegistry::current()->scriptLoader->addInline($trimmed, $requireList);
    }
}
