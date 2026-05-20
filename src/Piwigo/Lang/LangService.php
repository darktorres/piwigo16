<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Loader\PoLoader;
use Piwigo\Core\AppInfo;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\LanguageStack;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

final class LangService
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function l10n(?string $key, string|int|float|bool|null ...$args): string
    {
        return Lang::t($key ?? '', ...$args);
    }

    /**
     * Short translate API surfaced to plugin authors. Functionally identical
     * to l10n(); ships as the canonical name in PluginInterface docs so
     * plugins never call legacy `l10n()` or rely on global `Lang::t()`.
     */
    public function t(?string $key, string|int|float|bool|null ...$args): string
    {
        return Lang::t($key ?? '', ...$args);
    }

    /**
     * Discover and merge a plugin's `.po` translations.
     *
     * Looks for `<pluginDir>/language/<locale>/plugin.po` against the
     * standard fallback chain (current user locale → parent locale →
     * configured default). Plugins call this nowhere — PluginRegistry
     * invokes it during boot so plugin code only ever sees `t()`.
     *
     * @return bool true when a .po file was found and merged.
     */
    public function loadPluginTranslations(string $pluginId, string $pluginDir): bool
    {
        if ($pluginId === '' || $pluginDir === '') {
            return false;
        }
        return $this->loadLanguage('plugin', rtrim($pluginDir, '/') . '/') === true;
    }

    public function l10nDec(string $singularKey, string $pluralKey, int|float|null $decimal): string
    {
        return Translator::get()->plural($singularKey, $pluralKey, (int) $decimal);
    }

    /** @return array<mixed> */
    public function getL10nArgs(string $key, mixed $args = ''): array
    {
        if (is_array($args)) {
            $keyArg = array_merge([$key], $args);
        } else {
            $keyArg = [$key, $args];
        }
        return ['key_args' => $keyArg];
    }

    /** @param array<mixed>|string $keyArgs */
    public function l10nArgs(array|string $keyArgs, string $sep = "\n"): string
    {
        $result = '';
        if (is_array($keyArgs)) {
            foreach ($keyArgs as $key => $element) {
                if ($result !== '') {
                    $result .= $sep;
                }
                if ($key === 'key_args') {
                    $element = is_array($element) ? $element : [];
                    $shifted = array_shift($element);
                    array_unshift($element, Lang::t(is_string($shifted) ? $shifted : ''));
                    /** @var string $formatted */
                    $formatted = call_user_func_array(sprintf(...), $element);
                    $result   .= $formatted;
                } else {
                    /** @var array<mixed>|string $element */
                    $result .= $this->l10nArgs($element, $sep);
                }
            }
        } else {
            HtmlService::fatalError('l10n_args: Invalid arguments');
        }
        return $result;
    }

    public function getParentLanguage(?string $langId = null): ?string
    {
        if ($langId === null || $langId === '') {
            $info   = LanguageStack::info();
            $parent = $info['parent'] ?? null;
            return is_string($parent) && $parent !== '' ? $parent : null;
        }
        $f = $this->paths->root . 'language/' . $langId . '/common.lang.php';
        if (file_exists($f)) {
            include $f;
            /** @var array<string,mixed> $lang_info */
            return !empty($lang_info['parent']) && is_string($lang_info['parent']) ? $lang_info['parent'] : null;
        }
        return null;
    }

    /** @param array<mixed> $options */
    public function loadLanguage(string $filename, string $dirname = '', array $options = []): string|bool
    {
        $user = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];

        if (!empty($dirname) && !empty($filename) && empty($options['return'])
            && !LanguageStack::hasPluginFile($dirname, $filename)) {
            LanguageStack::trackPluginFile($dirname, $filename, $options);
        }

        if (empty($dirname)) {
            $dirname = $this->paths->root;
        }
        $langDir = $dirname . 'language/';

        $defaultLanguage = (InstallSentinel::isInstalled($this->paths) && RequestContextRegistry::current() !== RequestContext::Upgrade)
            ? Kernel::service(UserService::class)->getDefaultLanguage()
            : AppInfo::DEFAULT_LANGUAGE;

        $languages = [];
        if (!empty($options['language'])) {
            $languages[] = $options['language'];
        }
        if (!empty($user['language'])) {
            $languages[] = $user['language'];
        }
        if (($parent = $this->getParentLanguage()) !== null) {
            $languages[] = $parent;
        }
        if (isset($options['force_fallback'])) {
            $languages[] = $options['force_fallback'] === true ? $defaultLanguage : $options['force_fallback'];
        }
        if (empty($options['no_fallback'])) {
            $languages[] = $defaultLanguage;
        }

        /** @var list<string> $languagesTyped */
        $languagesTyped = array_values(array_unique(array_filter($languages, is_string(...))));

        if (isset($options['return']) && $options['return'] !== '') {
            foreach ($languagesTyped as $language) {
                $f = (isset($options['local']) && $options['local'] !== '')
                    ? $langDir . $language . '.' . $filename
                    : $langDir . $language . '/' . $filename;
                if (is_readable($f)) {
                    return file_get_contents($f);
                }
            }
            return false;
        }

        if (!empty($options['local'])) {
            foreach ($languagesTyped as $language) {
                $f = $langDir . $language . '.' . $filename . '.php';
                if (is_readable($f)) {
                    $lang      = null;
                    $lang_info = null;
                    // Language file path computed from runtime locale —
                    // Psalm cannot follow the include statically.
                    /** @psalm-suppress UnresolvableInclude */
                    include $f;
                    LanguageStack::mergeLang((array) $lang);
                    LanguageStack::mergeInfo((array) $lang_info);
                    return true;
                }
            }
            return false;
        }

        $domain = (string) preg_replace('/\.lang$/', '', $filename);
        $poFile = '';
        $selectedLanguage = '';
        foreach ($languagesTyped as $language) {
            $candidate = $langDir . $language . '/' . $domain . '.po';
            if (file_exists($candidate)) {
                $selectedLanguage = $language;
                $poFile           = $candidate;
                break;
            }
        }

        if ($selectedLanguage === '' || !is_readable($poFile)) {
            return false;
        }

        Translator::get()->load($selectedLanguage, $poFile);

        $poHeaders  = new PoLoader()->loadFile($poFile)->getHeaders();
        $langInfoPo = [];
        foreach ([
            'X-Piwigo-Language-Name' => 'language_name',
            'X-Piwigo-Country'       => 'country',
            'X-Piwigo-Direction'     => 'direction',
            'X-Piwigo-Code'          => 'code',
        ] as $header => $key) {
            if (($v = $poHeaders->get($header)) !== null) {
                $langInfoPo[$key] = $v;
            }
        }
        if (($v = $poHeaders->get('X-Piwigo-Zero-Plural')) !== null) {
            $langInfoPo['zero_plural'] = ($v === 'true');
        }
        LanguageStack::mergeInfo($langInfoPo);

        return true;
    }
}
