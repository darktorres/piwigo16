<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Builds a fresh `ExtensionContext` per plugin/theme -- `PluginRegistry`/
 * `ThemeRegistry` (P27.3) both need this identically, only the
 * `$extensionId` argument varies per call, so this factors out what would
 * otherwise be the same 20-collaborator constructor call duplicated in
 * both registries. Every collaborator here is a real, already-composed
 * service -- no new infrastructure, purely composition, same as
 * `ExtensionContext` itself.
 */
final readonly class ExtensionContextFactory
{
    public function __construct(
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private UserService $userService,
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private RedirectServiceInterface $redirectService,
        private AdminContext $adminContext,
        private EventDispatcher $eventDispatcher,
        private SessionService $sessionService,
        private ImageReadFacade $imageReadFacade,
        private Paths $paths,
        private ConfigService $configService,
        private EntityManagerInterface $entityManager,
        private MailService $mailService,
        private UserReadFacade $userReadFacade,
        private ThemeReadFacade $themeReadFacade,
        private CsrfService $csrfService,
        private HtmlRenderingInterface $htmlRenderer,
        private AccessControl $accessControl,
    ) {}

    public function build(PluginId|ThemeId $extensionId): ExtensionContext
    {
        return new ExtensionContext(
            $extensionId,
            $this->currentTemplate,
            $this->currentConfig,
            $this->currentUser,
            $this->userService,
            $this->lang,
            $this->urlService,
            $this->redirectService,
            $this->adminContext,
            $this->eventDispatcher,
            $this->sessionService,
            $this->imageReadFacade,
            $this->paths,
            $this->configService,
            $this->entityManager,
            $this->mailService,
            $this->userReadFacade,
            $this->themeReadFacade,
            $this->csrfService,
            $this->htmlRenderer,
            $this->accessControl,
        );
    }
}
