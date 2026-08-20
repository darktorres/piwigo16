<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPopuphelpPlaceholdersPageContext;
use Piwigo\Controller\Event\GetPopupHelpContent;
use Piwigo\Controller\Projection\PopuphelpView;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin-context help popup, distinct from the front-end
 * Piwigo\Controller\PopuphelpController. Not a `?page=` slug -- reached
 * as its own file path (`/admin/popuphelp.php`), invoked from the admin
 * UI's `popuphelp(url)` JS popup-window helper
 * (themes/default/js/scripts.js), so it has its own RouteDefinitions
 * entry rather than a config/admin_pages.php one.
 *
 * `IN_ADMIN` is read elsewhere (Piwigo\Page\PageHeaderRenderer), set by
 * the bootstrap file the same way admin.php itself sets it.
 *
 * `popuphelp.latte` declares `{layout 'layout.latte'}`, so
 * `PageHeaderRenderer::prepareContext()`/`PageTail::prepareContext()`
 * only prepare ambient context and `$this->renderer->render(...)` +
 * `Template::finalizeHtml()` produce the final string in one shot. The
 * `?page=` validation throws ResponseReadyException rather than dying
 * mid-render. The `output=content_only` branch returns $help_content
 * directly since by that point it is already fully computed -- that
 * branch never reaches PageTail, and skips PageHeaderRenderer too (the
 * `$output !== 'content_only'` guard above).
 */
final readonly class AdminPopuphelpController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private EventDispatcher $eventDispatcher,
        private LayoutState $layoutState,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;
        $output = $queryParams['output'] ?? null;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::prepareContext() below) --
        // no other file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        if ($output !== 'content_only') {
            $this->layoutState->setBodyId('thePopuphelpPage');
            $title = $this->lang->t('Piwigo Help');
            $this->layoutState->setPageBanner('<h1>' . $title . '</h1>');
            $this->layoutState->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);

            // set required template variables to avoid "Undefined array key" with PHP 8
            $template->assignContext(new AdminPopuphelpPlaceholdersPageContext());

            new PageHeaderRenderer()
                ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig);
        }

        if (! is_string($rawPage) || ! (bool) preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new ResponseReadyException(ResponseFactory::text('Request rejected: invalid page parameter', 400));
        }

        $help_content = $this->lang->load(
            'help/' . $rawPage . '.html',
            '',
            [
                'force_fallback' => 'en_UK',
                'return' => true,
            ]
        );
        if (! is_string($help_content)) {
            $help_content = '';
        }

        $help_content = $this->eventDispatcher->dispatch(new GetPopupHelpContent($help_content, $rawPage))
            ->content;

        if ($output === 'content_only') {
            return ResponseFactory::html($help_content);
        }

        PageTail::prepareContext();

        $html = $this->renderer->render(new PopuphelpView(helpContent: $help_content, isAdminContext: true));
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }
}
