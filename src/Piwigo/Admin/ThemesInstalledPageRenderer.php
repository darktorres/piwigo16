<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\ThemesInstalledPageContext;
use Piwigo\Admin\Request\ThemesInstalledActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Location\LocEndThemesInstalled;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * The "installed" tab of the "themes" page slug, dispatched by
 * ThemesSubController -- lists installed themes and handles
 * activate/deactivate/set_default/delete.
 *
 * ExtensionLifecycle::performAction() does no CSRF or privilege check of
 * its own, so the action-handling block below gates on isWebmaster() and
 * validates the token itself. The token is embedded into all 4
 * *_baseurl template assigns below -- themes_installed.latte appends the
 * theme ID directly after each *_baseurl string (no add_url_params() call
 * to hook into), so the token goes before the trailing '&amp;theme=' so
 * the template's own {$theme.ID} concatenation still works.
 */
final readonly class ThemesInstalledPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private CurrentLogger $currentLogger,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private ActivityService $activityService,
        private UserService $userService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private CurrentUser $currentUser,
        private Paths $paths,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * $pageSlug is an explicit param instead of `global $page['page'];` --
     * the one real caller (ThemesSubController) already knows its own
     * fixed page slug statically (it's the only class registered for the
     * 'themes' slug in config/admin_pages.php).
     */
    public function render(string $pageSlug): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $extension_repository = new ExtensionRepository($this->entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->paths, $this->currentUser, $this->eventDispatcher, $this->pluginRegistry, $this->themeRegistry, $this->entityManager);

        $themesAction = ThemesInstalledActionRequest::fromGlobals();
        if ($themesAction->action !== null and $themesAction->themeId !== null and $this->accessControl->isWebmaster()) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $fs_theme_entry = $extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$themesAction->themeId] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Theme, $themesAction->action, $themesAction->themeId, $fs_theme_entry);
            $this->pageState->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                if ($themesAction->action === 'activate' or $themesAction->action === 'deactivate') {
                    $template->deleteCompiledTemplates();
                }
                $this->redirectService->redirect($base_url);
            }
        }

        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape by design (see
        // that method's own docblock) -- every $fs_theme read below follows
        // its documented convention and reads specific keys defensively
        // instead.
        $fs_themes = $extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);

        // P27.5's own original gap here: ExtensionScanner used to recognize
        // only the legacy themeconf.inc.php header-comment format, so a
        // new-contract theme (theme.json only) was invisible to it. Since
        // P27.10 retired that legacy scan entirely, ExtensionScanner::
        // scanTheme() reads theme.json directly -- the same marker file
        // $this->themeRegistry->getAllManifests() itself scans, just
        // without its stricter opis/json-schema validation, so every id
        // the registry can find, the fs scan above already finds first (a
        // schema-valid theme.json is by construction also a valid one for
        // the fs scan's own looser read). This merge is very likely fully
        // redundant now -- kept as-is rather than removed in the same pass
        // that made it redundant, pending its own dedicated verification.
        foreach ($this->themeRegistry->getAllManifests() as $manifestId => $manifest) {
            if (isset($fs_themes[$manifestId])) {
                continue;
            }
            $fs_themes[$manifestId] = [
                'name' => $manifest->name,
                'uri' => $manifest->homepage ?? '',
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author ?? '',
                'author uri' => $manifest->authorUri,
                'parent' => $manifest->parent,
                'screenshot' => $manifest->assets['screenshot'] ?? '',
                'mobile' => false,
                'admin_uri' => null,
                'activable' => true,
            ];
        }

        uasort($fs_themes, $this->htmlRenderer->nameCompare(...));

        $default_theme = $this->userService
            ->getDefaultTheme();

        $db_theme_ids = array_keys($extension_repository->findAll(ExtensionType::Theme));

        $tpl_themes = [];

        foreach ($fs_themes as $theme_id => $fs_theme) {
            // golden_html_test: not a real theme, exists only so
            // Template::setTheme()'s standard_pages fallback has something
            // real to load before it swaps to themes/standard_pages -- see
            // its own theme.json description and
            // tests/Browser/GoldenHtmlSnapshotTest.php. A real directory
            // under themes/ with a valid theme.json, so ExtensionScanner
            // picks it up like any other installed theme unless excluded
            // here, same as 'default'/'standard_pages'.
            if ($theme_id === 'default' or $theme_id === 'standard_pages' or $theme_id === 'golden_html_test') {
                continue;
            }

            $tpl_themes[] = $this->buildTplTheme($theme_id, $fs_theme, $db_theme_ids, $default_theme, $extension_lifecycle);
        }

        usort($tpl_themes, $this->compareThemes(...));

        $pwg_token = $this->csrfService
            ->getToken();

        $this->eventDispatcher->dispatchNotify(new LocEndThemesInstalled());

        $template->assignContext(new ThemesInstalledPageContext(
            activateBaseUrl: $base_url . '&amp;action=activate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            deactivateBaseUrl: $base_url . '&amp;action=deactivate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            setDefaultBaseUrl: $base_url . '&amp;action=set_default&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            deleteBaseUrl: $base_url . '&amp;action=delete&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            tplThemes: $tpl_themes,
            isWebmaster: $this->accessControl->isWebmaster(),
            adminPageTitle: $this->lang->t('Themes'),
            enableExtensionsInstall: $this->currentConfig->enableExtensionsInstall,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'themes_installed.latte');
    }

    /**
     * Builds one $tpl_themes row -- extracted out of render()'s own foreach
     * body (Unit-testable via reflection with a real {@see ExtensionLifecycle}
     * and plain array/string inputs, the same pattern this class's own
     * compareThemes() below already uses; see
     * tests/Unit/Admin/ThemesInstalledPageRendererTest.php).
     *
     * @param array<string, mixed> $fs_theme ExtensionScanner's scanned entry
     *   for $theme_id -- see that method's own docblock for the precise
     *   per-key shape this reads defensively.
     * @param list<string> $db_theme_ids every theme id currently installed
     *   (has a DB row)
     * @return array<string, mixed>
     */
    private function buildTplTheme(
        string $theme_id,
        array $fs_theme,
        array $db_theme_ids,
        string $default_theme,
        ExtensionLifecycle $extension_lifecycle,
    ): array {
        $tpl_theme = [
            'ID' => $theme_id,
            'NAME' => $fs_theme['name'],
            'VISIT_URL' => $fs_theme['uri'],
            'VERSION' => $fs_theme['version'],
            'DESC' => $fs_theme['description'],
            'AUTHOR' => $fs_theme['author'],
            'AUTHOR_URL' => $fs_theme['author uri'] ?? null,
            'PARENT' => $fs_theme['parent'] ?? null,
            'SCREENSHOT' => $fs_theme['screenshot'],
            'IS_MOBILE' => $fs_theme['mobile'],
            'ADMIN_URI' => $fs_theme['admin_uri'] ?? null,
        ];

        if (in_array($theme_id, $db_theme_ids, true)) {
            $tpl_theme['STATE'] = 'active';
            $tpl_theme['IS_DEFAULT'] = ($theme_id === $default_theme);
            $tpl_theme['DEACTIVABLE'] = true;

            if (count($db_theme_ids) <= 1) {
                $tpl_theme['DEACTIVABLE'] = false;
                $tpl_theme['DEACTIVATE_TOOLTIP'] = $this->lang->t('Impossible to deactivate this theme, you need at least one theme.');
            }
            if ($tpl_theme['IS_DEFAULT']) {
                $tpl_theme['DEACTIVABLE'] = false;
                $tpl_theme['DEACTIVATE_TOOLTIP'] = $this->lang->t('Impossible to deactivate the default theme.');
            }
        } else {
            $tpl_theme['STATE'] = 'inactive';

            // is the theme "activable" ?
            // The (bool) cast is redundant: `!` already coerces its operand
            // to bool, so removing the cast can't change this condition's
            // truth value.
            if (isset($fs_theme['activable']) and ! (bool) $fs_theme['activable']) {
                $tpl_theme['ACTIVABLE'] = false;
                $tpl_theme['ACTIVABLE_TOOLTIP'] = $this->lang->t('This theme was not designed to be directly activated');
            } else {
                $tpl_theme['ACTIVABLE'] = true;
            }

            $missing_parent = $extension_lifecycle->missingParentTheme($theme_id, $fs_theme);
            if (isset($missing_parent)) {
                $tpl_theme['ACTIVABLE'] = false;

                $tpl_theme['ACTIVABLE_TOOLTIP'] = $this->lang->t(
                    'Impossible to activate this theme, the parent theme is missing: %s',
                    $missing_parent
                );
            }

            // is the theme "deletable" ?
            $children = $extension_lifecycle->getChildrenThemes($theme_id);

            $tpl_theme['DELETABLE'] = true;

            if (count($children) > 0) {
                $tpl_theme['DELETABLE'] = false;

                // array_filter() here is defense-in-depth, not covering a
                // reachable case: getChildrenThemes()'s own loop already
                // only ever appends a value after its own is_string() guard,
                // so $children is provably all-strings before this line
                // ever sees it.
                $tpl_theme['DELETE_TOOLTIP'] = $this->lang->t(
                    'Impossible to delete this theme. Other themes depends on it: %s',
                    implode(', ', array_filter($children, is_string(...)))
                );
            }
        }

        return $tpl_theme;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareThemes(array $a, array $b): int
    {
        // 'active'=>0 and 'inactive'=>1 are the only 2 weights this map can
        // ever produce (every other STATE falls through the `?? 1` below,
        // landing on 1 too) -- so the only comparisons that ever happen are
        // 0-vs-1. Decrementing 'active' to -1, or dropping the 'inactive'
        // entry entirely (its own `?? 1` fallback already equals its
        // removed value), can't change any `>=` outcome in that reduced
        // 2-value domain.
        $s = [
            'active' => 0,
            'inactive' => 1,
        ];

        // The (bool) casts below are redundant: if() already coerces its
        // condition to bool, so removing either cast can't change which
        // branch runs.
        if ((bool) ($a['IS_DEFAULT'] ?? false)) {
            return -1;
        }
        if ((bool) ($b['IS_DEFAULT'] ?? false)) {
            return 1;
        }

        // 'STATE' and 'NAME' are always plain strings in every $tpl_theme built
        // above ('STATE' is a string literal, 'NAME' comes from
        // ExtensionScanner's guaranteed-string invariant), but $a/$b are only
        // known as array<string, mixed> here since usort's callback signature
        // can't carry the caller's more precise shape.
        $a_state = $a['STATE'] ?? null;
        $b_state = $b['STATE'] ?? null;
        $a_state = is_string($a_state) ? $a_state : '';
        $b_state = is_string($b_state) ? $b_state : '';

        if ($a_state === $b_state) {
            $a_name = $a['NAME'] ?? null;
            $b_name = $b['NAME'] ?? null;
            $a_name = is_string($a_name) ? $a_name : '';
            $b_name = is_string($b_name) ? $b_name : '';
            return strcasecmp($a_name, $b_name);
        }

        // $a's own `?? 1` fallback and this whole comparison's left operand
        // being hardcoded to a fixed literal instead of reading $s[$b_state]
        // are both unobservable here: with only {0, 1} as real weights,
        // `>=` against any value in {0, 1} is decided entirely by whether
        // the OTHER side is exactly 0 -- and $a_state/$b_state being equal
        // (the only case where $a's own weight-vs-2-vs-1 distinction could
        // matter) is already handled by the strcasecmp() branch above,
        // never reaching this line.
        return ($s[$a_state] ?? 1) >= ($s[$b_state] ?? 1) ? 1 : -1;
    }
}
