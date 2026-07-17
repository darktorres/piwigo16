<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;

/**
 * Ported from admin/languages_installed.php (the "installed" tab of the
 * "languages" page slug, dispatched by LanguagesSubController) -- lists
 * installed languages and handles activate/deactivate/set_default/delete.
 *
 * P23 sub-batch 6i-1 fix: this file's action-handling block was already
 * gated on is_webmaster() and already allowlisted 'action'/'language' via
 * check_input_parameter(), but had zero check_pwg_token() call anywhere --
 * and ExtensionLifecycle::performAction() itself does no CSRF check of its
 * own either, so a crafted admin.php?page=languages&action=delete&language=X
 * GET request (an <img> tag on any page, no interaction beyond an active
 * webmaster session) could delete an active language. Fixed the same way
 * PictureModifyPageRenderer closed the sync_metadata gap in 6d: added
 * check_pwg_token() right after the existing guards, and embedded a real
 * token into $language['u_action']'s own add_url_params() call below --
 * languages_installed.tpl's 4 action links (activate/deactivate/
 * set_default/delete) all append '&action=X' onto that same u_action base,
 * so this one call site fixes all 4 links at once.
 */
final class LanguagesInstalledPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $conf, $page, $template;

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            // include/common.inc.php seeds $page['warnings'] as [] -- always an
            // array; defensively re-initialized here in case that invariant is
            // ever broken by a prior include.
            $page_warnings = $page['warnings'] ?? [];
            if (! is_array($page_warnings)) {
                $page_warnings = [];
            }
            $page_warnings[] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
            $page['warnings'] = $page_warnings;
        }

        $template->set_filenames([
            'languages' => 'languages_installed.tpl',
        ]);

        // admin.php sets $page['page'] to the requested admin page slug (a string)
        // before this renderer runs; narrow the mixed read-back from the
        // array<string, mixed>-typed $page global before interpolating into the URL.
        $page_id = $page['page'] ?? null;
        $page_id = is_scalar($page_id) ? (string) $page_id : '';
        $base_url = get_root_url() . 'admin.php?page=' . $page_id;

        $extension_repository = new ExtensionRepository(DbConnection::build());
        $pem_catalog = new PemCatalog(new ZipExtractor());
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($extension_repository, $pem_catalog);

        $fs_languages = $extension_scanner->scan(ExtensionType::Language);
        $db_languages = $extension_repository->findAll(ExtensionType::Language);

        // --------------------------------------------------perform requested actions
        (new \Piwigo\Validation\InputValidator())->validate('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
        (new \Piwigo\Validation\InputValidator())->validate('language', $_GET, false, '/^(' . join('|', array_keys($fs_languages)) . ')$/');

        if (isset($_GET['action']) and isset($_GET['language']) and \Piwigo\Auth\AccessControl::isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService());

            // check_input_parameter() above already fatal_error()s if either value
            // is non-scalar or doesn't match the expected pattern; query string
            // values reaching this point are always plain strings in practice, but
            // narrow explicitly for perform_action()'s string parameters.
            $action = $_GET['action'];
            $language_id = $_GET['language'];

            if (is_string($action) and is_string($language_id)) {
                $fs_language_entry = $fs_languages[$language_id] ?? null;
                $action_errors = $extension_lifecycle->performAction(ExtensionType::Language, $action, $language_id, $fs_language_entry);
                $page['errors'] = $action_errors;

                if ($action_errors === []) {
                    redirect($base_url);
                }
            }
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+
        $default_language = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultLanguage();

        $tpl_languages = [];

        foreach ($fs_languages as $language_id => $language) {
            $language['u_action'] = add_url_params($base_url, [
                'language' => $language_id,
                'pwg_token' => (new \Piwigo\Csrf\CsrfService())->getToken(),
            ]);

            if (in_array($language_id, array_keys($db_languages), true)) {
                $language['state'] = 'active';
                $language['deactivable'] = true;

                if (count($db_languages) <= 1) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, you need at least one language.');
                }

                if ($language_id === $default_language) {
                    $language['deactivable'] = false;
                    $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, first set another language as default.');
                }
            } else {
                $language['state'] = 'inactive';
            }

            if ($language_id === $default_language) {
                $language['is_default'] = true;
                array_unshift($tpl_languages, $language);
            } else {
                $language['is_default'] = false;
                $tpl_languages[] = $language;
            }
        }

        $template->assign(
            [
                'languages' => $tpl_languages,
            ]
        );
        $template->append('language_states', 'active');
        $template->append('language_states', 'inactive');

        $missing_language_ids = array_diff(
            array_keys($db_languages),
            array_keys($fs_languages)
        );

        foreach ($missing_language_ids as $language_id) {
            $extension_repository->reassignUsersFromLanguage($language_id, (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultLanguage());
            $extension_repository->delete(ExtensionType::Language, $language_id);
        }

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        $template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', $conf['enable_extensions_install']);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }
}
