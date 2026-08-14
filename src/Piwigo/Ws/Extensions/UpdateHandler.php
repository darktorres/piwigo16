<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.extensions.update` -- webmaster-only. Updates a plugin/theme/language.
 */
final readonly class UpdateHandler implements WsAction
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentTemplate $currentTemplate,
        private AccessControl $accessControl,
        private CurrentConfig $currentConfig,
        private ConfigService $configService,
        private ActivityService $activityService,
        private UserService $userService,
        private RedirectServiceInterface $redirectService,
        private PemCatalog $pemCatalog,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        if (! $this->currentConfig->enableExtensionsInstall) {
            return new WsErrorResponse(401, 'Piwigo extensions install/update system is disabled');
        }

        if (! $this->accessControl->isWebmaster()) {
            return new WsErrorResponse(401, $this->lang->t('Webmaster status is required.'));
        }

        $input = UpdateParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if (! in_array($input->type, ['plugins', 'themes', 'languages'], true)) {
            return new WsErrorResponse(403, 'invalid extension type');
        }

        $extension_id = $input->id;
        $revision = $input->revision;

        $type = match ($input->type) {
            'plugins' => ExtensionType::Plugin,
            'themes' => ExtensionType::Theme,
            // 'languages' is the only value that can still reach here: $type
            // is already restricted to plugins/themes/languages by the
            // in_array() guard above.
            default => ExtensionType::Language,
        };

        $urlService = $this->urlService;
        $scanner = new ExtensionScanner();
        $repo = new ExtensionRepository($this->entityManager);
        $pemCatalog = $this->pemCatalog;
        $lifecycle = new ExtensionLifecycle($this->lang, $repo, $pemCatalog, $urlService, $this->configService, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->paths, $this->currentUser, $this->eventDispatcher, $this->pluginRegistry, $this->themeRegistry, $this->entityManager);

        if ($type === ExtensionType::Plugin) {
            $dbPluginsById = $repo->findAll(ExtensionType::Plugin);
            if (
                isset($dbPluginsById[$extension_id])
                and $dbPluginsById[$extension_id]['state'] === 'active'
            ) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $extension_id, $fsEntry);

                $this->redirectService
                    ->redirect(
                        $urlService->getRootUrl()
                                . 'ws.php'
                                . '?method=pwg.extensions.update'
                                . '&type=plugins'
                                . '&id=' . $extension_id
                                . '&revision=' . $revision
                                . '&reactivate=true'
                                . '&pwg_token=' . new CsrfService($this->currentConfig)->getToken()
                                . '&format=json'
                    );
            }

            $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$extension_id] ?? null;
            $upgrade_status = $lifecycle->performAction(ExtensionType::Plugin, 'update', $extension_id, $fsEntry, [
                'revision' => $revision,
            ])[0] ?? null;
            $extension_name = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$extension_id]['name'] ?? $extension_id;

            if ($input->reactivateRequested) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'activate', $extension_id, $fsEntry);
            }
        } elseif ($type === ExtensionType::Theme) {
            $fsThemesBefore = $scanner->scan(ExtensionType::Theme, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
            $extension_name = $fsThemesBefore[$extension_id]['name'] ?? $extension_id;

            $extraction = $pemCatalog->extractArchive(ExtensionType::Theme, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction->status;

            $activity_details = [
                'theme_id' => $extension_id,
                'from_version' => $fsThemesBefore[$extension_id]['version'] ?? null,
            ];

            if ($upgrade_status === 'ok') {
                $fsThemesAfter = $scanner->scan(ExtensionType::Theme, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager); // refresh list
                $activity_details['to_version'] = $fsThemesAfter[$extension_id]['version'] ?? null;
            } else {
                $activity_details['result'] = 'error';
            }

            $this->activityService->record('system', ActivitySystem::Theme, 'update', $activity_details);
        } elseif ($type === ExtensionType::Language) {
            $extraction = $pemCatalog->extractArchive(ExtensionType::Language, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction->status;
            $extension_name = $scanner->scan(ExtensionType::Language, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$extension_id]['name'] ?? $extension_id;
        } else {
            // Unreachable: $type is derived from $input->type, already
            // restricted to plugins/themes/languages by the in_array()
            // guard above, and the 3 branches above exhaust every
            // ExtensionType case.
            throw new LogicException('Invalid extension type');
        }

        $template = $this->currentTemplate->get();

        /** @var Template $template */
        $template->deleteCompiledTemplates();

        return match ($upgrade_status) {
            'ok' => $this->lang->t('%s has been successfully updated.', $extension_name),
            'temp_path_error' => new WsErrorResponse(500, $this->lang->t('Can\'t create temporary file.')),
            'dl_archive_error' => new WsErrorResponse(500, $this->lang->t('Can\'t download archive.')),
            'archive_error' => new WsErrorResponse(500, $this->lang->t('Can\'t read or extract archive.')),
            default => new WsErrorResponse(500, $this->lang->t('An error occured during extraction (%s).', $upgrade_status)),
        };
    }
}
