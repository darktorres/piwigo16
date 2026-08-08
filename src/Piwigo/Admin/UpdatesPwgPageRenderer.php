<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Request\UpdatesPwgRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * The "pwg" tab of the "updates" page slug, dispatched by
 * UpdatesSubController -- Piwigo core self-update: check for a new
 * version, then a 2-step POST-based upgrade flow.
 *
 * The step 2/3 POST handlers below call CoreUpdateService::upgradeTo() (a
 * real core version upgrade -- downloads and extracts a new Piwigo
 * release over the live application), which has no built-in CSRF check of
 * its own, so each handler validates the token itself before calling it;
 * gating on isWebmaster() alone would not stop a cross-origin
 * auto-submitting POST form from a logged-in webmaster's browser.
 * PWG_TOKEN below feeds the hidden pwg_token field in both forms,
 * matching the convention used by
 * themes_standard_pages.tpl/plugins_installed.tpl.
 */
final class UpdatesPwgPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CoreUpdateService $coreUpdateService,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableCoreUpdate()) {
            $this->htmlRenderer
                ->fatalError('Piwigo core update system is disabled');
        }

        /*
        STEP:
        0 = check is needed. If version is latest or check fail, we stay on step 0
        1 = new version on same branch AND new branch are available => user may choose upgrade.
        2 = upgrade on same branch
        3 = upgrade on different branch
        */
        $containerInfo = ContainerDetector::detect();
        $ct_env = $containerInfo->type;
        $ct_build_version = $containerInfo->version;

        $updatesPwgRequest = UpdatesPwgRequest::fromGlobals($ct_env, $this->inputValidator);
        $step = $updatesPwgRequest->step;
        $upgrade_to = $updatesPwgRequest->upgradeTo;

        if ($ct_env === 'Official') {
            $template->assign([
                'CONTAINER_VERSION' => $ct_build_version,
                'DOCKER_UPDATE_GUIDE_URL' => AppInfo::URL . '/guide-update-docker',
            ]);
        }

        $core_update_service = $this->coreUpdateService;
        $new_versions = $core_update_service->getPiwigoNewVersions();

        // +-----------------------------------------------------------------------+
        // |                                Step 0                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 0) {
            if ($new_versions->minor !== null and $new_versions->major !== null) {
                $step = 1;
                $upgrade_to = $new_versions->major;
            } elseif ($new_versions->minor !== null) {
                $step = 2;
                $upgrade_to = $new_versions->minor;
            } elseif ($new_versions->major !== null) {
                $step = 3;
                $upgrade_to = $new_versions->major;
            }

            $template->assign('CHECK_VERSION', $new_versions->piwigoOrgChecked);
            $template->assign('DEV_VERSION', $new_versions->isDev);
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
        if ($step === 2 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                                Step 3                                 |
        // +-----------------------------------------------------------------------+
        if ($step === 3 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }

            $extension_update_checker = $this->extensionUpdateChecker;
            $template->assign('missing', $extension_update_checker->getMissingExtensions($upgrade_to));
        }

        // +-----------------------------------------------------------------------+
        // | Check for requirements                                                |
        // +-----------------------------------------------------------------------+

        if ($new_versions->minorPhp !== null and version_compare(PHP_VERSION, $new_versions->minorPhp, '<')) {
            $template->assign('MINOR_RELEASE_PHP_REQUIRED', $new_versions->minorPhp);
        }

        if ($new_versions->majorPhp !== null and version_compare(PHP_VERSION, $new_versions->majorPhp, '<')) {
            $template->assign('MAJOR_RELEASE_PHP_REQUIRED', $new_versions->majorPhp);
        }

        // +-----------------------------------------------------------------------+
        // |                        Process template                               |
        // +-----------------------------------------------------------------------+

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $template->assign(
            [
                'STEP' => $step,
                'PIWIGO_CURRENT_VERSION' => $this->pageState->updatedVersion ?? AppInfo::VERSION,
                'UPGRADE_TO' => $upgrade_to,
                'PWG_TOKEN' => new CsrfService($this->currentConfig)
                    ->getToken(),
            ]
        );

        if ($new_versions->minor !== null) {
            $template->assign(
                [
                    'MINOR_VERSION' => $new_versions->minor,
                    'MINOR_RELEASE_URL' => (
                        ($ct_env === 'Official')
                    ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions->minor)
                    : AppInfo::URL . '/releases/' . $new_versions->minor
                    ),
                ]
            );
        }

        if ($new_versions->major !== null) {
            $template->assign(
                [
                    'MAJOR_VERSION' => $new_versions->major,
                    'MAJOR_RELEASE_URL' => AppInfo::URL . '/releases/' .
                      (($ct_env === 'Official') ? substr($new_versions->major, 0, -1) : $new_versions->major),
                    'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions->major),
                    'MAJOR_VERSION_PWG' => preg_replace('/[a-z]$/', '', $new_versions->major), // Remove container build ver
                ]
            );
        }

        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Updates'));
        $template->set_filename('plugin_admin_content', 'updates_pwg.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
    }
}
