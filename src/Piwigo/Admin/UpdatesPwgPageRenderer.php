<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Admin\Projection\UpdatesPwgView;
use Piwigo\Admin\Request\UpdatesPwgRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Template\Renderer;
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
 * themes_standard_pages.latte/plugins_installed.latte.
 */
final readonly class UpdatesPwgPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private PageState $pageState,
        private CoreUpdateService $coreUpdateService,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Renderer $renderer,
    ) {}

    public function render(): AdminPageResult
    {
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

        // Read unconditionally, not inside the `$step === 0` branch below
        // (P58-A's §11). Both are plain bools on NewVersionsInfo and
        // getPiwigoNewVersions() has already run, so the values are the
        // same ones that branch used to set -- and updates_pwg.latte reads
        // them only inside its own `{if $step == 0}`, which is exactly the
        // case where the branch did set them. The correlation is gone
        // rather than documented, and the two View properties are bool.
        $check_version = $new_versions->piwigoOrgChecked;
        $dev_version = $new_versions->isDev;

        // $step arrives straight from $_GET as an int, with no check that
        // the release the mode it selects would present actually exists.
        // Step 1 offers a choice between the two branches, step 2 upgrades
        // on the same branch and step 3 to a new one, and each renders that
        // release's version and URL unguarded -- so `?step=3` on an
        // up-to-date install rendered an empty version badge inside an
        // empty href and passed null to htmlspecialchars(). Steps 2 and 3
        // are also the two that call CoreUpdateService::upgradeTo() on a
        // submitted form.
        //
        // Fall back to 0 rather than to a nearby step: 0 means "check is
        // needed", and the block below then derives whichever step the
        // available versions really support -- 2 if only a minor exists, 3
        // if only a major, and 0 (the check page) if neither.

        // CSRF runs before that validation, not after, so a forged
        // submission is still rejected outright instead of falling through
        // to the check page with a 200. Same condition the two action
        // blocks below use; they no longer check it themselves.
        if ($updatesPwgRequest->isUpgradeSubmitted
            and ($step === 2 or $step === 3)
            and $this->accessControl->isWebmaster()
        ) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $stepHasRelease = match ($step) {
            1 => $new_versions->minor !== null && $new_versions->major !== null,
            2 => $new_versions->minor !== null,
            3 => $new_versions->major !== null,
            default => true,
        };
        if (! $stepHasRelease) {
            $step = 0;
        }

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
        }

        if ($step === 1) {
            // nothing to do here
        }

        if ($step === 2 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }
        }

        // Two flat lists, not the type-keyed bag: that bag has three ways
        // to say "nothing missing" -- absent key, empty list, or no bag at
        // all off step 3 -- which is what forced updates_pwg.latte to
        // collapse five reads through empty(). The key lookup itself lives
        // in ExtensionUpdateChecker, where it can be tested; asking for the
        // wrong key here is silent by construction (P58-B2).
        $missing_plugins = [];
        $missing_themes = [];
        if ($step === 3 and $this->accessControl->isWebmaster()) {
            if ($updatesPwgRequest->isUpgradeSubmitted) {
                $core_update_service->upgradeTo($updatesPwgRequest->upgradeToSubmitted, $step);
            }

            $extension_update_checker = $this->extensionUpdateChecker;
            $missing_plugins = self::toMissingRows($extension_update_checker->getMissingPlugins($upgrade_to));
            $missing_themes = self::toMissingRows($extension_update_checker->getMissingThemes($upgrade_to));
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
                ? 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . (preg_replace('/\./', '', $new_versions->minor) ?? '')
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
            $major_docker_release_url = 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#' . (preg_replace('/\./', '', $new_versions->major) ?? '');
        }

        $adminContent = $this->renderer->render(new UpdatesPwgView(
            containerVersion: $container_version,
            dockerUpdateGuideUrl: $docker_update_guide_url,
            checkVersion: $check_version,
            devVersion: $dev_version,
            missingPlugins: $missing_plugins,
            missingThemes: $missing_themes,
            minorReleasePhpRequired: $minor_release_php_required,
            majorReleasePhpRequired: $major_release_php_required,
            step: $step,
            piwigoCurrentVersion: $this->pageState->updatedVersion ?? AppInfo::VERSION,
            upgradeTo: $upgrade_to,
            csrfToken: $this->csrfService
                ->getToken(),
            minorVersion: $minor_version,
            minorReleaseUrl: $minor_release_url,
            majorVersion: $major_version,
            majorReleaseUrl: $major_release_url,
            majorDockerReleaseUrl: $major_docker_release_url,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Updates'),
        );
    }

    /**
     * `updates_pwg.latte` only ever reads `uri`/`name` off each row (the
     * "may not be compatible" link lists), so the scan rows are converted
     * at the template boundary rather than carried further.
     *
     * @param list<PluginScanRow|ThemeScanRow|LanguageScanRow> $rows
     * @return list<array{uri: string, name: string}>
     */
    private static function toMissingRows(array $rows): array
    {
        return array_map(
            static fn (PluginScanRow|ThemeScanRow|LanguageScanRow $row): array => [
                'uri' => $row->uri,
                'name' => $row->name,
            ],
            $rows
        );
    }
}
