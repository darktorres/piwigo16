<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Extensions\ExtensionAction;
use Piwigo\Admin\Languages;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.extensions.update` — upgrade a plugin/theme/language from PEM. */
final readonly class UpdateHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private RedirectResponder $redirectResponder,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if (!Config::enableExtensionsInstall()) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(401, Lang::t('Webmaster status is required.'));
        }
        try {
            $input = UpdateParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!in_array($input->type, ['plugins', 'themes', 'languages'])) {
            return new PwgError(403, 'invalid extension type');
        }
        $type          = $input->type;
        $extensionId   = $input->id;
        $revision      = $input->revision;
        $upgradeStatus = 'ok';
        $extensionName = '';
        if ($type === 'plugins') {
            $extension = Kernel::service(Plugins::class);
            if (isset($extension->db_plugins_by_id[$extensionId]) && $extension->db_plugins_by_id[$extensionId]->state === 'active') {
                $extension->performAction(ExtensionAction::Deactivate, $extensionId);
                $this->redirectResponder->redirect($this->urlGenerator->ws(['method' => 'pwg.extensions.update', 'type' => 'plugins', 'id' => $extensionId, 'revision' => $revision, 'reactivate' => 'true', 'pwg_token' => $this->csrfService->getToken(), 'format' => 'json']));
            }
            $performResult = $extension->performAction(ExtensionAction::Update, $extensionId, $revision);
            $upgradeStatus = is_array($performResult) ? ($performResult[0] ?? 'ok') : 'ok';
            $upgradeStatus = is_string($upgradeStatus) ? $upgradeStatus : 'ok';
            $extensionName = is_string($extension->fs_plugins[$extensionId]['name'] ?? null) ? $extension->fs_plugins[$extensionId]['name'] : '';
            if ($input->reactivate) {
                $extension->performAction(ExtensionAction::Activate, $extensionId);
            }
        } elseif ($type === 'themes') {
            $extension      = Kernel::service(Themes::class);
            $upgradeStatus  = $extension->extractThemeFiles('upgrade', $revision, $extensionId);
            $extensionName  = is_string($extension->fs_themes[$extensionId]['name'] ?? null) ? $extension->fs_themes[$extensionId]['name'] : '';
            $fromVersion    = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            $activityDetails = ['theme_id' => $extensionId, 'from_version' => $fromVersion];
            if ($upgradeStatus === 'ok') {
                $extension->getFsThemes();
                $activityDetails['to_version'] = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            } else {
                $activityDetails['result'] = 'error';
            }
            $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Theme, 'update', $activityDetails));
        } elseif ($type === 'languages') {
            $extension     = Kernel::service(Languages::class);
            $upgradeStatus = $extension->extractLanguageFiles('upgrade', $revision, $extensionId);
            $extensionName = is_string($extension->fs_languages[$extensionId]['name'] ?? null) ? $extension->fs_languages[$extensionId]['name'] : '';
        }
        TemplateRegistry::current()->deleteCompiledTemplates();
        return match ($upgradeStatus) {
            'ok'               => Lang::t('%s has been successfully updated.', $extensionName),
            'temp_path_error'  => new PwgError(null, Lang::t('Can\'t create temporary file.')),
            'dl_archive_error' => new PwgError(null, Lang::t('Can\'t download archive.')),
            'archive_error'    => new PwgError(null, Lang::t('Can\'t read or extract archive.')),
            default            => new PwgError(null, Lang::t('An error occured during extraction (%s).', $upgradeStatus)),
        };
    }
}
