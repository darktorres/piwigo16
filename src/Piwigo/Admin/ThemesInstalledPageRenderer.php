<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocEndThemesInstalled;
use Piwigo\Template\Template;

/**
 * Ported from admin/themes_installed.php (the "installed" tab of the
 * "themes" page slug, dispatched by ThemesSubController) -- lists installed
 * themes and handles activate/deactivate/set_default/delete.
 *
 * P23 sub-batch 6i-2 fix: this file's action-handling block had NO
 * is_webmaster() gate at all on the mutation itself (only an earlier,
 * non-blocking warning message used it) and zero check_pwg_token() call
 * anywhere -- worse than the languages gap fixed in 6i-1 (which at least had
 * is_webmaster()). ExtensionLifecycle::performAction() itself does no CSRF
 * or privilege check of its own either, so a crafted
 * admin.php?page=themes&action=delete&theme=X GET request -- reachable by
 * any logged-in admin session, no interaction beyond that -- could delete a
 * theme. Fixed by adding both is_webmaster() and check_pwg_token() to the
 * action-handling block, and embedding a real token into all 4
 * *_baseurl template assigns below -- themes_installed.tpl appends the theme
 * ID directly after each *_baseurl string (no add_url_params() call to hook
 * into, unlike languages), so the token goes before the trailing
 * '&amp;theme=' so the template's own {$theme.ID} concatenation still works.
 */
final class ThemesInstalledPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Activity\ActivityService $activityService,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    /**
     * Legacy Coupling Retirement Track A batch A5.2f: $pageSlug is an
     * explicit param instead of `global $page['page'];` -- the one real
     * caller (ThemesSubController) already knows its own fixed page
     * slug statically (it's the only class registered for the 'themes'
     * slug in config/admin_pages.php).
     */
    public function render(string $pageSlug): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $conn = DbConnection::build();
        $extension_repository = new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($conn));
        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger);
        $extension_scanner = new ExtensionScanner();
        $plugin_migration_repo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Admin\Extensions\PluginMigrationEntity::class);
        $extension_lifecycle = new ExtensionLifecycle($this->lang, $extension_repository, $pem_catalog, $this->urlService, $this->configService, $plugin_migration_repo, $this->activityService, $this->userService, $this->htmlRenderer);

        // +-----------------------------------------------------------------------+
        // |                          perform actions                              |
        // +-----------------------------------------------------------------------+

        $themesAction = Request\ThemesInstalledActionRequest::fromGlobals();
        if ($themesAction->action !== null and $themesAction->themeId !== null and $this->accessControl->isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $fs_theme_entry = $extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang)[$themesAction->themeId] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Theme, $themesAction->action, $themesAction->themeId, $fs_theme_entry);
            $this->pageState->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                if ($themesAction->action === 'activate' or $themesAction->action === 'deactivate') {
                    $template->delete_compiled_templates();
                }
                $this->redirectService->redirect($base_url);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape by design (see
        // that method's own docblock) -- every $fs_theme read below follows
        // its documented convention and reads specific keys defensively
        // instead.
        $fs_themes = $extension_scanner->scan(ExtensionType::Theme, $this->urlService, $this->lang);
        uasort($fs_themes, $this->htmlRenderer->nameCompare(...));

        $default_theme = $this->userService
            ->getDefaultTheme();

        $db_theme_ids = array_keys($extension_repository->findAll(ExtensionType::Theme));

        $tpl_themes = [];

        foreach ($fs_themes as $theme_id => $fs_theme) {
            if ($theme_id === 'default' or $theme_id === 'standard_pages') {
                continue;
            }

            $tpl_themes[] = $this->buildTplTheme($theme_id, $fs_theme, $db_theme_ids, $default_theme, $extension_lifecycle);
        }

        usort($tpl_themes, $this->compareThemes(...));

        $pwg_token = new \Piwigo\Csrf\CsrfService()
            ->getToken();

        $template->assign(
            [
                'activate_baseurl' => $base_url . '&amp;action=activate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
                'deactivate_baseurl' => $base_url . '&amp;action=deactivate&amp;pwg_token=' . $pwg_token . '&amp;theme=',
                'set_default_baseurl' => $base_url . '&amp;action=set_default&amp;pwg_token=' . $pwg_token . '&amp;theme=',
                'delete_baseurl' => $base_url . '&amp;action=delete&amp;pwg_token=' . $pwg_token . '&amp;theme=',

                'tpl_themes' => $tpl_themes,
            ]
        );

        $this->eventDispatcher->dispatchNotify(new LocEndThemesInstalled());

        $template->assign('isWebmaster', ($this->accessControl->isWebmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Themes'));
        $template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', \Piwigo\Config\CurrentConfig::enableExtensionsInstall());

        $template->set_filenames([
            'themes' => 'themes_installed.tpl',
        ]);
        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
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
            // truth value. Confirmed while investigating a mutation-testing
            // gap.
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
                // ever sees it. Confirmed while investigating a
                // mutation-testing gap.
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
        // 2-value domain. Confirmed while investigating a mutation-testing
        // gap.
        $s = [
            'active' => 0,
            'inactive' => 1,
        ];

        // The (bool) casts below are redundant: if() already coerces its
        // condition to bool, so removing either cast can't change which
        // branch runs. Confirmed while investigating a mutation-testing gap.
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
        // never reaching this line. Confirmed while investigating a
        // mutation-testing gap.
        return ($s[$a_state] ?? 1) >= ($s[$b_state] ?? 1) ? 1 : -1;
    }
}
