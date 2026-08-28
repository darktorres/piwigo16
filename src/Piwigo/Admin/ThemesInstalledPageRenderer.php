<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Event\ThemesInstalledPageRendered;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\ThemeListRow;
use Piwigo\Admin\Projection\ThemesInstalledView;
use Piwigo\Admin\Request\ThemesInstalledActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
        private Renderer $renderer,
    ) {}

    /**
     * $pageSlug is an explicit param instead of `global $page['page'];` --
     * the one real caller (ThemesSubController) already knows its own
     * fixed page slug statically (it's the only class registered for the
     * 'themes' slug in config/admin_pages.php).
     */
    public function render(string $pageSlug): AdminPageResult
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

            $fs_theme_entry = $extension_scanner->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager)[$themesAction->themeId] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Theme, $themesAction->action, $themesAction->themeId, $fs_theme_entry);
            $this->pageState->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                if ($themesAction->action === 'activate' or $themesAction->action === 'deactivate') {
                    $template->deleteCompiledTemplates();
                }
                $this->redirectService->redirect($base_url);
            }
        }

        $fs_themes = $extension_scanner->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager);

        // ExtensionScanner::scanTheme() reads theme.json directly -- the
        // same marker file $this->themeRegistry->getAllManifests() itself
        // scans, just without its stricter opis/json-schema validation, so
        // every id the registry can find, the fs scan above already finds
        // first (a schema-valid theme.json is by construction also a
        // valid one for the fs scan's own looser read). This merge is
        // very likely fully redundant -- kept as-is pending its own
        // dedicated verification.
        foreach ($this->themeRegistry->getAllManifests() as $manifestId => $manifest) {
            if (isset($fs_themes[$manifestId])) {
                continue;
            }
            $screenshot = $manifest->assets['screenshot'] ?? null;
            $fs_themes[$manifestId] = new ThemeScanRow(
                id: $manifestId,
                name: $manifest->name,
                version: $manifest->version,
                uri: $manifest->homepage ?? '',
                description: $manifest->description,
                author: $manifest->author ?? '',
                mobile: false,
                screenshot: is_string($screenshot) ? $screenshot : '',
                authorUri: $manifest->authorUri,
                parent: $manifest->parent,
                activable: true,
            );
        }

        // Piwigo\Html\HtmlService::nameCompare() (HtmlRenderingInterface's
        // own real, still-generic array<string, mixed> $a/$b contract)
        // doesn't fit a real ThemeScanRow object directly -- inlined here
        // rather than wrapping each row back into an array just to satisfy
        // that signature, same strcmp()-on-strtolower() logic.
        uasort($fs_themes, static fn (ThemeScanRow $a, ThemeScanRow $b): int => strcmp(strtolower($a->name), strtolower($b->name)));

        $default_theme = $this->userService
            ->getDefaultTheme();

        $db_theme_ids = array_keys($extension_repository->findAllThemes());

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

        $this->eventDispatcher->dispatch(new ThemesInstalledPageRendered());

        $adminContent = $this->renderer->render(new ThemesInstalledView(
            activateBaseUrl: $base_url . '&amp;action=activate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            deactivateBaseUrl: $base_url . '&amp;action=deactivate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            setDefaultBaseUrl: $base_url . '&amp;action=set_default&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            deleteBaseUrl: $base_url . '&amp;action=delete&amp;pwg_token=' . $pwg_token . '&amp;theme=',
            tplThemes: $tpl_themes,
            isWebmaster: $this->accessControl->isWebmaster() ? 1 : 0,
            enableExtensionsInstall: $this->currentConfig->enableExtensionsInstall,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Themes'),
        );
    }

    /**
     * Builds one $tpl_themes row -- extracted out of render()'s own foreach
     * body (Unit-testable via reflection with a real {@see ExtensionLifecycle}
     * and plain array/string inputs, the same pattern this class's own
     * compareThemes() below already uses; see
     * tests/Unit/Admin/ThemesInstalledPageRendererTest.php).
     *
     * @param list<string> $db_theme_ids every theme id currently installed
     *   (has a DB row)
     */
    private function buildTplTheme(
        string $theme_id,
        ThemeScanRow $fs_theme,
        array $db_theme_ids,
        string $default_theme,
        ExtensionLifecycle $extension_lifecycle,
    ): ThemeListRow {
        if (in_array($theme_id, $db_theme_ids, true)) {
            $state = 'active';
            $isDefault = $theme_id === $default_theme;
            $deactivable = true;

            if (count($db_theme_ids) <= 1) {
                $deactivable = false;
            }
            if ($isDefault) {
                $deactivable = false;
            }

            return new ThemeListRow(
                id: $theme_id,
                name: $fs_theme->name,
                visitUrl: $fs_theme->uri,
                version: $fs_theme->version,
                desc: $fs_theme->description,
                author: $fs_theme->author,
                authorUrl: $fs_theme->authorUri,
                isMobile: $fs_theme->mobile,
                screenshot: $fs_theme->screenshot,
                adminUri: $fs_theme->adminUri,
                state: $state,
                isDefault: $isDefault,
                deactivable: $deactivable,
                activable: true,
                activableTooltip: null,
                deletable: true,
                deleteTooltip: null,
            );
        }

        $state = 'inactive';

        // is the theme "activable" ?
        if ($fs_theme->activable === false) {
            $activable = false;
            $activableTooltip = $this->lang->t('This theme was not designed to be directly activated');
        } else {
            $activable = true;
            $activableTooltip = null;
        }

        $missing_parent = $extension_lifecycle->missingParentTheme($theme_id, $fs_theme);
        if (isset($missing_parent)) {
            $activable = false;

            $activableTooltip = $this->lang->t(
                'Impossible to activate this theme, the parent theme is missing: %s',
                $missing_parent
            );
        }

        // is the theme "deletable" ?
        $children = $extension_lifecycle->getChildrenThemes($theme_id);

        $deletable = true;
        $deleteTooltip = null;

        if (count($children) > 0) {
            $deletable = false;

            // array_filter() here is defense-in-depth, not covering a
            // reachable case: getChildrenThemes()'s own loop already
            // only ever appends a value after its own is_string() guard,
            // so $children is provably all-strings before this line
            // ever sees it.
            $deleteTooltip = $this->lang->t(
                'Impossible to delete this theme. Other themes depends on it: %s',
                implode(', ', array_filter($children, is_string(...)))
            );
        }

        return new ThemeListRow(
            id: $theme_id,
            name: $fs_theme->name,
            visitUrl: $fs_theme->uri,
            version: $fs_theme->version,
            desc: $fs_theme->description,
            author: $fs_theme->author,
            authorUrl: $fs_theme->authorUri,
            isMobile: $fs_theme->mobile,
            screenshot: $fs_theme->screenshot,
            adminUri: $fs_theme->adminUri,
            state: $state,
            isDefault: false,
            deactivable: false,
            activable: $activable,
            activableTooltip: $activableTooltip,
            deletable: $deletable,
            deleteTooltip: $deleteTooltip,
        );
    }

    private function compareThemes(ThemeListRow $a, ThemeListRow $b): int
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

        if ($a->isDefault) {
            return -1;
        }
        if ($b->isDefault) {
            return 1;
        }

        $a_state = $a->state;
        $b_state = $b->state;

        if ($a_state === $b_state) {
            return strcasecmp($a->name, $b->name);
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
