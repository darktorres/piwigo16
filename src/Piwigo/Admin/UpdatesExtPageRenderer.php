<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\Template;

/**
 * Ported from admin/updates_ext.php -- cross-type "which installed
 * plugins/themes/languages have a pending update" check. Shared class
 * (P23 sub-batch 6i-4), matching the ThemesStandardPagesPageRenderer "one
 * class, multiple call sites" precedent from 6i-2: called both from
 * UpdatesSubController's own "ext" tab and from LanguagesSubController/
 * ThemesSubController/PluginsSubController's own "update" tab (each of
 * which still applies its own ADMIN_PAGE_TITLE override after this
 * render() call, exactly as it did after the raw include before this
 * port).
 *
 * No CSRF gap here (confirmed by direct read and by tracing this page's
 * own ignore/reset/update actions to ws.php?method=
 * pwg.extensions.ignoreUpdate/pwg.extensions.update, both of which check
 * get_pwg_token() against $params['pwg_token'] themselves in
 * include/ws_functions/pwg.extensions.php) -- unlike its sibling
 * UpdatesPwgPageRenderer, which had a real, severe gap.
 */
final class UpdatesExtPageRenderer
{
    /**
     * Legacy Coupling Retirement Track A batch A5.2f: $pageSlug is an
     * explicit param instead of `global $page['page'];` -- this class has
     * 4 real callers (UpdatesSubController's own "ext" tab, and
     * LanguagesSubController/ThemesSubController/PluginsSubController's
     * own "update" tab), each of which already knows its own fixed page
     * slug statically (config/admin_pages.php registers each of those 4
     * controllers for exactly one slug), so each passes its own literal.
     */
    public function render(string $pageSlug, UrlServiceInterface $urlService, ConfigService $configService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Config\Config::enableExtensionsInstall()) {
            new \Piwigo\Html\HtmlService()
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $updates_ignored = \Piwigo\Config\Config::updatesIgnored();

        // updates.class.php::__construct() restricts itself to a single type when
        // reached from that type's own page (?page=plugins&tab=update etc.), and
        // checks all 3 when reached from ?page=updates&tab=ext -- same selection
        // logic here, keyed by the plural page-slug form used throughout this file
        // and its template (EXT_TYPE, UPDATES_EXTENSION[type]).
        $plural_by_type = [
            ExtensionType::Plugin->value => 'plugins',
            ExtensionType::Theme->value => 'themes',
            ExtensionType::Language->value => 'languages',
        ];
        $types_to_check = in_array($pageSlug, $plural_by_type, true)
            ? array_filter(ExtensionType::cases(), static fn (ExtensionType $type): bool => $plural_by_type[$type->value] === $pageSlug)
            : ExtensionType::cases();

        $extension_update_checker = new ExtensionUpdateChecker(new ExtensionScanner(), new PemCatalog(new ZipExtractor()), $urlService, $configService);

        // Investigated, not reproduced exactly: updates.class.php::get_server_extensions()
        // makes ONE combined, uncategorized (no pem_*_category get_data key) PEM
        // call across every type in $this->types, whereas ExtensionUpdateChecker
        // (built on PemCatalog, shared with the plugins/themes/languages listing
        // pages) makes one categorized call per type -- an intentional consequence
        // of this batch's "one generic service per concern" decision. Both end up
        // asking PEM for the same core-branch version list per category, so the
        // rendered "needs update" outcome is expected to be identical; only the
        // number/shape of outbound PEM requests differs.
        $show_reset = false;
        $updates_extension = []; // The array of the updates of a type of extension is stored in $updates_extension[type]
        // updates.class.php's own get_server_extensions() shares ONE PEM call
        // across every type, so it's an all-or-nothing success -- since this batch
        // makes one call per type instead, require every type to succeed to match
        // that same all-or-nothing outcome, rather than silently degrading to a
        // partial result no legacy code path could ever produce.
        $all_types_reachable = true;

        // PEM_URL is defined via define('PEM_URL', \Piwigo\Config\Config::alternativePemUrl()) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary -- narrow it once here (see the
        // identical narrowing in updates::get_server_extensions()).
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        foreach ($types_to_check as $extension_type) {
            $type = $plural_by_type[$extension_type->value];

            $pending = $extension_update_checker->getPendingUpdates($extension_type);
            if ($pending === null) {
                $all_types_reachable = false;
                continue;
            }

            if ($pending === []) {
                continue;
            }

            $type_updates = [];

            $ignored_ids = $updates_ignored[$type] ?? [];
            if (! is_array($ignored_ids)) {
                $ignored_ids = [];
            }

            foreach ($pending as $ext_id => $data) {
                $fs_ext = $data['fs'];
                $ext_info = $data['server'];

                $fs_version_raw = $fs_ext['version'] ?? null;
                $fs_version = is_string($fs_version_raw) ? $fs_version_raw : '';

                $revision_name_raw = $ext_info['revision_name'] ?? null;
                $revision_name = is_string($revision_name_raw) ? $revision_name_raw : '';

                $extension_id_raw = $ext_info['extension_id'] ?? null;
                $extension_id = (is_string($extension_id_raw) || is_int($extension_id_raw)) ? $extension_id_raw : '';
                $download_url_raw = $ext_info['download_url'] ?? null;
                $download_url = is_string($download_url_raw) ? $download_url_raw : '';
                $revision_description_raw = $ext_info['revision_description'] ?? null;
                $revision_description = is_string($revision_description_raw) ? $revision_description_raw : '';

                $type_updates[] = [
                    'ID' => $extension_id,
                    'REVISION_ID' => $ext_info['revision_id'],
                    'EXT_ID' => $ext_id,
                    'EXT_NAME' => $fs_ext['name'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id . '#changelog',
                    'REV_DESC' => trim($revision_description, " \n\r"),
                    'CURRENT_VERSION' => $fs_version,
                    'NEW_VERSION' => $revision_name,
                    'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
                    'IGNORED' => in_array($ext_id, $ignored_ids, true),
                ];
            }

            $updates_extension[$type] = $type_updates;

            if ($ignored_ids !== []) {
                $show_reset = true;
            }
        }

        if (! $all_types_reachable) {
            \Piwigo\Core\PageState::current()->addError(Lang::t('Can\'t connect to server.'));
            return; // TODO: remove this return and add a proper "page killer"
        }

        $template->assign('UPDATES_EXTENSION', $updates_extension);
        $template->assign('SHOW_RESET', $show_reset);
        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());
        $template->assign('EXT_TYPE', $pageSlug === 'updates' ? 'extensions' : $pageSlug);
        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);
        $template->set_filename('plugin_admin_content', 'updates_ext.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Updates'));
    }
}
