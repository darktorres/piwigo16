<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
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
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
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
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;

        $conn = DbConnection::build();
        $extension_repository = new ExtensionRepository($conn);
        $pem_catalog = new PemCatalog(new ZipExtractor());
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($extension_repository, $pem_catalog, $this->urlService, $this->configService);

        // +-----------------------------------------------------------------------+
        // |                          perform actions                              |
        // +-----------------------------------------------------------------------+

        if (isset($_GET['action']) and is_string($_GET['action']) and isset($_GET['theme']) and is_string($_GET['theme']) and \Piwigo\Auth\AccessControl::isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            $fs_theme_entry = $extension_scanner->scan(ExtensionType::Theme, $this->urlService)[$_GET['theme']] ?? null;
            $action_errors = $extension_lifecycle->performAction(ExtensionType::Theme, $_GET['action'], $_GET['theme'], $fs_theme_entry);
            \Piwigo\Core\PageState::current()->errors = array_values(array_filter($action_errors, is_string(...)));

            if ($action_errors === []) {
                if ($_GET['action'] === 'activate' or $_GET['action'] === 'deactivate') {
                    $template->delete_compiled_templates();
                }
                $this->redirectService->redirect($base_url);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        $fs_themes = $extension_scanner->scan(ExtensionType::Theme, $this->urlService);
        uasort($fs_themes, \Piwigo\Bootstrap\PresentationAccessor::htmlService()->nameCompare(...));

        $default_theme = \Piwigo\Bootstrap\CoreDomainAccessor::userService()
            ->getDefaultTheme();

        $db_theme_ids = array_keys($extension_repository->findAll(ExtensionType::Theme));

        $tpl_themes = [];

        foreach ($fs_themes as $theme_id => $fs_theme) {
            if ($theme_id === 'default' or $theme_id === 'standard_pages') {
                continue;
            }

            $tpl_theme = [
                'ID' => $theme_id,
                'NAME' => $fs_theme['name'],
                'VISIT_URL' => $fs_theme['uri'],
                'VERSION' => $fs_theme['version'],
                'DESC' => $fs_theme['description'],
                'AUTHOR' => $fs_theme['author'],
                'AUTHOR_URL' => @$fs_theme['author uri'],
                'PARENT' => @$fs_theme['parent'],
                'SCREENSHOT' => $fs_theme['screenshot'],
                'IS_MOBILE' => $fs_theme['mobile'],
                'ADMIN_URI' => @$fs_theme['admin_uri'],
            ];

            if (in_array($theme_id, $db_theme_ids, true)) {
                $tpl_theme['STATE'] = 'active';
                $tpl_theme['IS_DEFAULT'] = ($theme_id === $default_theme);
                $tpl_theme['DEACTIVABLE'] = true;

                if (count($db_theme_ids) <= 1) {
                    $tpl_theme['DEACTIVABLE'] = false;
                    $tpl_theme['DEACTIVATE_TOOLTIP'] = Lang::t('Impossible to deactivate this theme, you need at least one theme.');
                }
                if ($tpl_theme['IS_DEFAULT']) {
                    $tpl_theme['DEACTIVABLE'] = false;
                    $tpl_theme['DEACTIVATE_TOOLTIP'] = Lang::t('Impossible to deactivate the default theme.');
                }
            } else {
                $tpl_theme['STATE'] = 'inactive';

                // is the theme "activable" ?
                if (isset($fs_theme['activable']) and ! (bool) $fs_theme['activable']) {
                    $tpl_theme['ACTIVABLE'] = false;
                    $tpl_theme['ACTIVABLE_TOOLTIP'] = Lang::t('This theme was not designed to be directly activated');
                } else {
                    $tpl_theme['ACTIVABLE'] = true;
                }

                $missing_parent = $extension_lifecycle->missingParentTheme($theme_id, $fs_theme);
                if (isset($missing_parent)) {
                    $tpl_theme['ACTIVABLE'] = false;

                    $tpl_theme['ACTIVABLE_TOOLTIP'] = Lang::t(
                        'Impossible to activate this theme, the parent theme is missing: %s',
                        $missing_parent
                    );
                }

                // is the theme "deletable" ?
                $children = $extension_lifecycle->getChildrenThemes($theme_id);

                $tpl_theme['DELETABLE'] = true;

                if (count($children) > 0) {
                    $tpl_theme['DELETABLE'] = false;

                    $tpl_theme['DELETE_TOOLTIP'] = Lang::t(
                        'Impossible to delete this theme. Other themes depends on it: %s',
                        implode(', ', array_filter($children, is_string(...)))
                    );
                }
            }

            $tpl_themes[] = $tpl_theme;
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

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_themes_installed');

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));
        $template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', \Piwigo\Config\CurrentConfig::enableExtensionsInstall());

        $template->set_filenames([
            'themes' => 'themes_installed.tpl',
        ]);
        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareThemes(array $a, array $b): int
    {
        $s = [
            'active' => 0,
            'inactive' => 1,
        ];

        if ((bool) @$a['IS_DEFAULT']) {
            return -1;
        }
        if ((bool) @$b['IS_DEFAULT']) {
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

        return ($s[$a_state] ?? 1) >= ($s[$b_state] ?? 1) ? 1 : -1;
    }
}
