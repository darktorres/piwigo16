<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Projection\UpdatesPwgPageContext;
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
 * CSRF_TOKEN below feeds the hidden pwg_token field in both forms,
 * matching the convention used by
 * themes_standard_pages.tpl/plugins_installed.tpl.
 */
final readonly class UpdatesPwgPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private CoreUpdateService $coreUpdateService,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableCoreUpdate) {
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

        $container_version = null;
        $docker_update_guide_url = null;
        if ($ct_env === 'Official') {
            $container_version = $ct_build_version;
            $docker_update_guide_url = AppInfo::URL . '/guide-update-docker';
        }

        $core_update_service = $this->coreUpdateService;
        $new_versions = $core_update_service->getPiwigoNewVersions();

        $check_version = null;
        $dev_version = null;
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

            $check_version = $new_versions->piwigoOrgChecked;
            $dev_version = $new_versions->isDev;
        }

        if ($step === 1) {
            // nothing to do here
        }

        if ($step === 2 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }
        }

        $missing = null;
        if ($step === 3 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }

            $extension_update_checker = $this->extensionUpdateChecker;
            $missing = $extension_update_checker->getMissingExtensions($upgrade_to);
        }

        $minor_release_php_required = null;
        if ($new_versions->minorPhp !== null and version_compare(PHP_VERSION, $new_versions->minorPhp, '<')) {
            $minor_release_php_required = $new_versions->minorPhp;
        }

        $major_release_php_required = null;
        if ($new_versions->majorPhp !== null and version_compare(PHP_VERSION, $new_versions->majorPhp, '<')) {
            $major_release_php_required = $new_versions->majorPhp;
        }

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $minor_version = null;
        $minor_release_url = null;
        if ($new_versions->minor !== null) {
            $minor_version = $new_versions->minor;
            $minor_release_url = ($ct_env === 'Official')
                ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions->minor)
                : AppInfo::URL . '/releases/' . $new_versions->minor;
        }

        $major_version = null;
        $major_release_url = null;
        $major_docker_release_url = null;
        $major_version_pwg = null;
        if ($new_versions->major !== null) {
            $major_version = $new_versions->major;
            $major_release_url = AppInfo::URL . '/releases/' .
              (($ct_env === 'Official') ? substr($new_versions->major, 0, -1) : $new_versions->major);
            $major_docker_release_url = 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . preg_replace('/\./', '', $new_versions->major);
            $major_version_pwg = preg_replace('/[a-z]$/', '', $new_versions->major); // Remove container build ver
        }

        $template->assignContext(new UpdatesPwgPageContext(
            containerVersion: $container_version,
            dockerUpdateGuideUrl: $docker_update_guide_url,
            checkVersion: $check_version,
            devVersion: $dev_version,
            missing: $missing,
            minorReleasePhpRequired: $minor_release_php_required,
            majorReleasePhpRequired: $major_release_php_required,
            step: $step,
            piwigoCurrentVersion: $this->pageState->updatedVersion ?? AppInfo::VERSION,
            upgradeTo: $upgrade_to,
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            minorVersion: $minor_version,
            minorReleaseUrl: $minor_release_url,
            majorVersion: $major_version,
            majorReleaseUrl: $major_release_url,
            majorDockerReleaseUrl: $major_docker_release_url,
            majorVersionPwg: $major_version_pwg,
            adminPageTitle: $this->lang->t('Updates'),
        ));
        $template->assignVarFromTemplate('ADMIN_CONTENT', 'updates_pwg.latte');
    }
}
