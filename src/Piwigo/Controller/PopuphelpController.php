<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
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
 * Replaces popuphelp.php (front-end help popup -- distinct from
 * admin/popuphelp.php, a standalone root-style entry point untouched
 * since P21). check_status() stays outside the render logic on purpose:
 * it can throw ResponseReadyException on failure directly, same as every
 * other controller (Workstream C3).
 *
 * Workstream C3c: converted off LegacyRenderCapture's ob_start()/
 * ob_get_contents() capture, same "nothing in this chain echoes -- Page
 * HeaderRenderer only ever called assign()/parse($handle, false)
 * internally, parse('popuphelp', false) accumulates into Template's own
 * $output buffer, PageTail::renderToString() drains that whole buffer as
 * one string" mechanism Controller\AboutController already established.
 * The `?page=` validation now throws ResponseReadyException instead of
 * die()ing mid-render -- this drops the former "preserve partial legacy
 * HTML then die()" behavior LegacyRenderCapture's own docblock used to
 * document as intentional, deliberately: nothing has actually been
 * echoed to a real Response body by this point any more (Template's own
 * accumulator buffer isn't drained until PageTail::renderToString() at
 * the very end), so there's no partial HTML left to preserve -- a clean
 * 400 reject is strictly more correct, matching how
 * Controller\ActionController::doError() already works.
 */
final class PopuphelpController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;

        // Legacy popuphelp.php also did `define('PWG_HELP', true);`
        // here -- confirmed via a project-wide grep that nothing reads
        // that constant anywhere (not even admin/popuphelp.php, which
        // defines the same constant for its own, unrelated reasons);
        // dropped rather than ported, and src/Piwigo/ itself is
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
            throw new ResponseReadyException(ResponseFactory::text('Hacking attempt!', 400));
        }

        $help_content = $this->lang->load('help/' . $rawPage . '.html', '', [
            'return' => true,
        ]);
        if (! is_string($help_content)) {
            $help_content = '';
        }

        $help_content = $this->eventDispatcher->dispatchChange(new GetPopupHelpContent($help_content, $rawPage))
            ->content;

        $template->set_filename('popuphelp', 'popuphelp.tpl');
        $template->assign([
            'HELP_CONTENT' => $help_content,
        ]);

        $template->parse('popuphelp', false);

        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
