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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.plugins.performAction` -- install/activate/deactivate/uninstall/delete a plugin.
 */
final readonly class PluginsPerformActionHandler implements WsAction
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
        private PemCatalog $pemCatalog,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        $template = $this->currentTemplate->get();
        $input = PluginsPerformActionParams::fromArray($params);

        /** @var Template $template */
        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (! $this->accessControl->isWebmaster()) {
            return new WsErrorResponse(403, $this->lang->t('Webmaster status is required.'));
        }

        if (! $this->currentConfig->enableExtensionsInstall and $input->action === 'delete') {
            return new WsErrorResponse(401, 'Piwigo extensions install/update/delete system is disabled');
        }

        // No define('IN_ADMIN', true) call here: Template::__construct()
        // (the only reader that matters mid-request) already ran during
        // common.inc.php's bootstrap, before this WS callback executes,
        // and deleteCompiledTemplates() below and plugins::
        // perform_action() never read IN_ADMIN themselves. A define()
        // here would also violate the SEC-60 arch-test rule (no define()
        // under src/Piwigo/).

        $urlService = $this->urlService;
        $lifecycle = new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository($this->entityManager),
            $this->pemCatalog,
            $urlService,
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
            ->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager)[$input->plugin] ?? null;
        $errors = $lifecycle->performAction(ExtensionType::Plugin, $input->action, $input->plugin, $fsEntry);

        if ($errors !== []) {
            return new WsErrorResponse(500, implode(', ', array_filter($errors, is_string(...))));
        } else {
            if (in_array($input->action, ['activate', 'deactivate'], true)) {
                $template->deleteCompiledTemplates();
            }
            return true;
        }
    }
}
