<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Projection\PopuphelpPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Event\Admin\GetPopupHelpContent;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs popuphelp.php (front-end help popup), distinct from
 * admin/popuphelp.php, a separate root-style entry point. check_status()
 * runs before any render logic, since it throws ResponseReadyException
 * directly on failure, the same pattern every controller here uses.
 *
 * Nothing in this chain echoes directly: PageHeaderRenderer only calls
 * assign()/parse($handle, false) internally, parse('popuphelp', false)
 * accumulates into Template's own $output buffer, and
 * PageTail::renderToString() drains that whole buffer as one string at
 * the end. Because nothing has been echoed to the Response body before
 * that point, the `?page=` validation below can throw
 * ResponseReadyException on an invalid value with a clean 400 response --
 * there is no partial HTML to preserve.
 */
final readonly class PopuphelpController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;

        // Legacy popuphelp.php also did `define('PWG_HELP', true);`
        // here -- nothing reads that constant anywhere (not even
        // admin/popuphelp.php, which defines the same constant for its
        // own, unrelated reasons); dropped rather than ported, and
        // src/Piwigo/ itself is
        // arch-tested to contain zero define() calls at all
        // (tests/Arch/StructuralTest.php).

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no
        // other file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $this->pageState->setBodyId('thePopuphelpPage');
        $title = $this->lang->t('Piwigo Help');
        $this->pageState->setPageBanner('');
        $this->pageState->setMetaRobots([
            'noindex' => 1,
            'nofollow' => 1,
        ]);
        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);

        if (! is_string($rawPage) || ! (bool) preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new ResponseReadyException(ResponseFactory::text('Request rejected: invalid page parameter', 400));
        }

        $help_content = $this->lang->load('help/' . $rawPage . '.html', '', [
            'return' => true,
        ]);
        if (! is_string($help_content)) {
            $help_content = '';
        }

        $help_content = $this->eventDispatcher->dispatchChange(new GetPopupHelpContent($help_content, $rawPage))
            ->content;

        $template->assignContext(new PopuphelpPageContext(helpContent: $help_content));

        $template->parse('popuphelp.latte', false);

        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
