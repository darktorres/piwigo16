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
 * `POST /api/v1/plugins/{id}/actions/perform` --
 * `pwg.plugins.performAction`'s real replacement, webmaster + CSRF
 * (stricter than plain admin, gated by `AccessControl::isWebmaster()`).
 */
final readonly class PluginPerformActionController implements ControllerInterface
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

        if (! $this->accessControl->isWebmaster()) {
            return ResponseFactory::problem('Forbidden', 403, $this->lang->t('Webmaster status is required.'));
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $pluginId = is_array($routeArgs) && is_string($routeArgs['id'] ?? null) ? $routeArgs['id'] : '';

        $input = PluginActionInput::fromArray(JsonBody::decode($request));

        if (! $this->currentConfig->enableExtensionsInstall && $input->action === 'delete') {
            return ResponseFactory::problem('Forbidden', 403, 'Piwigo extensions install/update/delete system is disabled.');
        }

        $lifecycle = new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository($this->entityManager),
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
        $fsEntry = new ExtensionScanner()
            ->scan(
                ExtensionType::Plugin,
                $this->urlService,
                $this->lang,
                $this->paths,
                $this->currentUser,
                $this->eventDispatcher,
                $this->currentConfig,
                $this->entityManager
            )[$pluginId] ?? null;

        $errors = $lifecycle->performAction(ExtensionType::Plugin, $input->action, $pluginId, $fsEntry);

        if ($errors !== []) {
            return ResponseFactory::problem('Unprocessable Entity', 422, implode(', ', array_filter($errors, is_string(...))));
        }

        if (in_array($input->action, ['activate', 'deactivate'], true)) {
            $this->currentTemplate->get()
                ->deleteCompiledTemplates();
        }

        return ResponseFactory::noContent();
    }
}
