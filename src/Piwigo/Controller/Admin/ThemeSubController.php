<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Controller\Admin\Request\ThemeIdRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\SettingsPageInterface;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/theme.php's own body (page slug "theme"), folded directly
 * into this controller -- validates the requested theme id against
 * ExtensionScanner's own scan (already migrated off the legacy
 * themes.class.php god-class), then dispatches to that theme's own
 * `PluginConfig\SettingsPageInterface::handleSettingsRequest()`,
 * booted fresh for this one request via `ThemeRegistry::
 * bootForSettingsPage()` -- see that method's own docblock for why a
 * page-scoped, throwaway boot is the right shape here (unlike
 * `PluginSubController`, which reads an already-`bootActive()`d instance
 * instead). No other real caller of admin/theme.php exists (confirmed via
 * grep) -- admin.php's own routing already gates this page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the shell's
 * own (redundant) copy of that check is dropped here.
 *
 * $_GET['theme'] parsing/validation is extracted into
 * Request\ThemeIdRequest -- see that class's own docblock.
 */
final readonly class ThemeSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private ThemeRegistry $themeRegistry,
        private CurrentTemplate $currentTemplate,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $theme = ThemeIdRequest::fromGlobals($this->inputValidator)->themeId;

        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
        if (! in_array($theme, array_keys($fs_themes), true)) {
            $this->htmlRenderer
                ->fatalError('Invalid theme');
        }

        $instance = $this->themeRegistry->bootForSettingsPage($theme);
        if (! $instance instanceof SettingsPageInterface) {
            $this->htmlRenderer
                ->fatalError('Theme ' . $theme . ' has no settings page');
        }

        $view = $instance->handleSettingsRequest($request);
        $adminContent = $this->renderer->render($view);

        $this->currentTemplate->get()
            ->assignContext(new AdminContentPageContext(adminContent: $adminContent));
    }
}
