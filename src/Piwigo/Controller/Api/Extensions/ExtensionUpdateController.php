<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Override;
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
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/extensions/{type}/{id}/actions/update` --
 * `pwg.extensions.update`'s real replacement, webmaster + CSRF.
 *
 * The plugin branch's "was this plugin active before the update" handling
 * is a single atomic sequence: read "was it active" once up front,
 * deactivate before updating if so, and reactivate afterward -- no
 * redirect involved (redirecting a JSON API call makes no sense, and
 * `RedirectServiceInterface` isn't a fit for this pipeline).
 */
final readonly class ExtensionUpdateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentTemplate $currentTemplate,
        private AccessControl $accessControl,
        private CurrentConfig $currentConfig,
        private ConfigService $configService,
        private ActivityService $activityService,
        private UserService $userService,
        private PemCatalog $pemCatalog,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        if (! $this->currentConfig->enableExtensionsInstall) {
            return ResponseFactory::problem('Forbidden', 403, 'Piwigo extensions install/update system is disabled.');
        }

        if (! $this->accessControl->isWebmaster()) {
            return ResponseFactory::problem('Forbidden', 403, $this->lang->t('Webmaster status is required.'));
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $routeArgs = is_array($routeArgs) ? $routeArgs : [];
        $typeParam = is_string($routeArgs['type'] ?? null) ? $routeArgs['type'] : '';
        $extensionId = is_string($routeArgs['id'] ?? null) ? $routeArgs['id'] : '';

        $type = ExtensionType::fromPluralParam($typeParam);
        if (! $type instanceof ExtensionType) {
            return ResponseFactory::problem('Not Found', 404, 'Invalid extension type.');
        }

        $input = ExtensionUpdateInput::fromArray(JsonBody::decode($request));

        $scanner = new ExtensionScanner();
        $repo = new ExtensionRepository($this->entityManager);
        $lifecycle = new ExtensionLifecycle(
            $this->lang,
            $repo,
            $this->pemCatalog,
            $this->urlService,
            $this->configService,
            $this->activityService,
            $this->userService,
            $this->htmlRenderer,
            $this->currentConfig,
            $this->paths,
            $this->currentUser,
            $this->eventDispatcher,
            $this->pluginRegistry,
            $this->themeRegistry,
            $this->entityManager,
        );

        $scan = fn (ExtensionType $t): array => $scanner->scan(
            $t,
            $this->urlService,
            $this->lang,
            $this->paths,
            $this->currentUser,
            $this->eventDispatcher,
            $this->currentConfig,
            $this->entityManager
        );

        if ($type === ExtensionType::Plugin) {
            $dbPluginsById = $repo->findAll(ExtensionType::Plugin);
            $wasActive = isset($dbPluginsById[$extensionId]) && $dbPluginsById[$extensionId]['state'] === 'active';

            if ($wasActive) {
                $fsEntry = $scan(ExtensionType::Plugin)[$extensionId] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $extensionId, $fsEntry);
            }

            $fsEntry = $scan(ExtensionType::Plugin)[$extensionId] ?? null;
            $upgradeStatus = $lifecycle->performAction(ExtensionType::Plugin, 'update', $extensionId, $fsEntry, [
                'revision' => $input->revision,
            ])[0] ?? null;
            $extensionName = $scan(ExtensionType::Plugin)[$extensionId]['name'] ?? $extensionId;

            if ($wasActive) {
                $fsEntry = $scan(ExtensionType::Plugin)[$extensionId] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'activate', $extensionId, $fsEntry);
            }
        } elseif ($type === ExtensionType::Theme) {
            $fsThemesBefore = $scan(ExtensionType::Theme);
            $extensionName = $fsThemesBefore[$extensionId]['name'] ?? $extensionId;

            $extraction = $this->pemCatalog->extractArchive(ExtensionType::Theme, 'upgrade', $input->revision, $extensionId);
            $upgradeStatus = $extraction->status;

            $activityDetails = [
                'theme_id' => $extensionId,
                'from_version' => $fsThemesBefore[$extensionId]['version'] ?? null,
            ];

            if ($upgradeStatus === 'ok') {
                $fsThemesAfter = $scan(ExtensionType::Theme);
                $activityDetails['to_version'] = $fsThemesAfter[$extensionId]['version'] ?? null;
            } else {
                $activityDetails['result'] = 'error';
            }

            $this->activityService->record('system', ActivitySystem::Theme, 'update', $activityDetails);
        } else {
            $extraction = $this->pemCatalog->extractArchive(ExtensionType::Language, 'upgrade', $input->revision, $extensionId);
            $upgradeStatus = $extraction->status;
            $extensionName = $scan(ExtensionType::Language)[$extensionId]['name'] ?? $extensionId;
        }

        $this->currentTemplate->get()
            ->deleteCompiledTemplates();

        if ($upgradeStatus !== 'ok') {
            $message = match ($upgradeStatus) {
                'temp_path_error' => $this->lang->t('Can\'t create temporary file.'),
                'dl_archive_error' => $this->lang->t('Can\'t download archive.'),
                'archive_error' => $this->lang->t('Can\'t read or extract archive.'),
                default => $this->lang->t('An error occured during extraction (%s).', (string) $upgradeStatus),
            };

            return ResponseFactory::problem('Unprocessable Entity', 422, $message);
        }

        return ResponseFactory::json([
            'message' => $this->lang->t('%s has been successfully updated.', $extensionName),
        ]);
    }
}
