<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\Template;

/**
 * Ported from admin/updates_pwg.php (the "pwg" tab of the "updates" page
 * slug, dispatched by UpdatesSubController) -- Piwigo core self-update:
 * check for a new version, then a 2-step POST-based upgrade flow.
 *
 * P23 sub-batch 6i-4 fix: the most severe CSRF gap found across the whole
 * P23 batch 6 admin-absorption effort. The step 2/3 POST handlers below
 * called CoreUpdateService::upgradeTo() (a real core version upgrade --
 * downloads and extracts a new Piwigo release over the live application)
 * gated only on is_webmaster(), with zero check_pwg_token() call anywhere
 * in the file, and both corresponding <form method="post"> blocks in
 * updates_pwg.tpl carried zero pwg_token field (confirmed via direct grep)
 * -- only a hidden 'upgrade_to' whose value (the next version number) is
 * publicly knowable, not a secret. CoreUpdateService::upgradeTo() itself
 * has no built-in CSRF check either. A malicious cross-origin
 * auto-submitting POST form targeting a logged-in webmaster could trigger
 * a real, unconfirmed core upgrade. Fixed by adding check_pwg_token() to
 * both POST handlers and a hidden pwg_token field to both forms (assigned
 * here as PWG_TOKEN, matching the convention already used by
 * themes_standard_pages.tpl/plugins_installed.tpl).
 */
final class UpdatesPwgPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Config\Config::enableCoreUpdate()) {
            new \Piwigo\Html\HtmlService()
                ->fatalError('Piwigo core update system is disabled');
        }

        /*
        STEP:
        0 = check is needed. If version is latest or check fail, we stay on step 0
        1 = new version on same branch AND new branch are available => user may choose upgrade.
        2 = upgrade on same branch
        3 = upgrade on different branch
        */
        $step_param = $_GET['step'] ?? 0;
        $step = (is_string($step_param) || is_int($step_param)) ? (int) $step_param : 0;

        [$ct_env, $ct_build_version] = \Piwigo\Core\ContainerDetector::detect();

        if ($ct_env === 'Official') {
            $template->assign([
                'CONTAINER_VERSION' => $ct_build_version,
                'DOCKER_UPDATE_GUIDE_URL' => PHPWG_URL . '/guide-update-docker',
            ]);
            // Remove optional ? on [a-z]? since it will only be available on piwigo 16.3
            // Docker images started to use letter suffix in 16.2
            new \Piwigo\Validation\InputValidator()
                ->validate('to', $_GET, false, '/^\d+\.\d+\.\d+[a-z]?$/');
            $get_to = $_GET['to'] ?? null;
            $upgrade_to = is_string($get_to) ? (preg_replace('/[a-z]$/', '', $get_to) ?? '') : '';
        } else {
            new \Piwigo\Validation\InputValidator()
                ->validate('to', $_GET, false, '/^\d+\.\d+\.\d+$/');
            $get_to = $_GET['to'] ?? '';
            $upgrade_to = is_string($get_to) ? $get_to : '';
        }

        $core_update_service = new CoreUpdateService(new ZipExtractor(), $this->redirectService, $this->urlService, $this->configService);
        $new_versions = $core_update_service->getPiwigoNewVersions();

        // +-----------------------------------------------------------------------+
        // |                                Step 0                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 0) {
            if (isset($new_versions['minor']) and isset($new_versions['major']) and is_string($new_versions['major'])) {
                $step = 1;
                $upgrade_to = $new_versions['major'];
            } elseif (isset($new_versions['minor']) and is_string($new_versions['minor'])) {
                $step = 2;
                $upgrade_to = $new_versions['minor'];
            } elseif (isset($new_versions['major']) and is_string($new_versions['major'])) {
                $step = 3;
                $upgrade_to = $new_versions['major'];
            }

            $template->assign('CHECK_VERSION', $new_versions['piwigo.org-checked']);
            $template->assign('DEV_VERSION', $new_versions['is_dev']);
        }

        // +-----------------------------------------------------------------------+
        // |                                Step 1                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 1) {
            // nothing to do here
        }

        // +-----------------------------------------------------------------------+
        // |                                Step 2                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 2 and \Piwigo\Auth\AccessControl::isWebmaster()) {
            if (isset($_POST['submit']) and isset($_POST['upgrade_to']) and is_string($_POST['upgrade_to'])) {
                new \Piwigo\Csrf\CsrfService()
                    ->checkOrFail(new \Piwigo\Html\HtmlService(), $this->redirectService);
                $core_update_service->upgradeTo($_POST['upgrade_to'], $step);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                                Step 3                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 3 and \Piwigo\Auth\AccessControl::isWebmaster()) {
            if (isset($_POST['submit']) and isset($_POST['upgrade_to']) and is_string($_POST['upgrade_to'])) {
                new \Piwigo\Csrf\CsrfService()
                    ->checkOrFail(new \Piwigo\Html\HtmlService(), $this->redirectService);
                $core_update_service->upgradeTo($_POST['upgrade_to'], $step);
            }

            $extension_update_checker = new ExtensionUpdateChecker(new ExtensionScanner(), new PemCatalog(new ZipExtractor()), $this->urlService, $this->configService);
            $template->assign('missing', $extension_update_checker->getMissingExtensions($upgrade_to));
        }

        // +-----------------------------------------------------------------------+
        // | Check for requirements                                                |
        // +-----------------------------------------------------------------------+

        if (isset($new_versions['minor_php']) and is_string($new_versions['minor_php']) and version_compare(PHP_VERSION, $new_versions['minor_php'], '<')) {
            $template->assign('MINOR_RELEASE_PHP_REQUIRED', $new_versions['minor_php']);
        }

        if (isset($new_versions['major_php']) and is_string($new_versions['major_php']) and version_compare(PHP_VERSION, $new_versions['major_php'], '<')) {
            $template->assign('MAJOR_RELEASE_PHP_REQUIRED', $new_versions['major_php']);
        }

        // +-----------------------------------------------------------------------+
        // |                        Process template                               |
        // +-----------------------------------------------------------------------+

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $template->assign(
            [
                'STEP' => $step,
                'PIWIGO_CURRENT_VERSION' => \Piwigo\Core\PageState::current()->updatedVersion ?? AppInfo::VERSION,
                'UPGRADE_TO' => $upgrade_to,
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        if (isset($new_versions['minor']) and is_string($new_versions['minor'])) {
            $template->assign(
                [
                    'MINOR_VERSION' => $new_versions['minor'],
                    'MINOR_RELEASE_URL' => (
                        ($ct_env === 'Official')
                    ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions['minor'])
                    : PHPWG_URL . '/releases/' . $new_versions['minor']
                    ),
                ]
            );
        }

        if (isset($new_versions['major']) and is_string($new_versions['major'])) {
            $template->assign(
                [
                    'MAJOR_VERSION' => $new_versions['major'],
                    'MAJOR_RELEASE_URL' => PHPWG_URL . '/releases/' .
                      (($ct_env === 'Official') ? substr($new_versions['major'], 0, -1) : $new_versions['major']),
                    'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions['major']),
                    'MAJOR_VERSION_PWG' => preg_replace('/[a-z]$/', '', $new_versions['major']), // Remove container build ver
                ]
            );
        }

        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Updates'));
        $template->set_filename('plugin_admin_content', 'updates_pwg.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
    }
}
