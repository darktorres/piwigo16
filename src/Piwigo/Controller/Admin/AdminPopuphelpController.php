<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

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
 * Replaces admin/popuphelp.php -- the admin-context help popup, distinct
 * from Piwigo\Controller\PopuphelpController (the front-end one, its own
 * docblock already flags this file as "a standalone root-style entry point
 * untouched since P21"). Not a `?page=` slug -- reached as its own real
 * file path (`/admin/popuphelp.php`), invoked from the admin UI's
 * `popuphelp(url)` JS popup-window helper (themes/default/js/scripts.js),
 * so it gets its own config/routes.php entry rather than a
 * config/admin_pages.php one.
 *
 * `PWG_HELP` dropped the same way the front-end port already dropped it
 * (confirmed via a project-wide grep: nothing reads that constant anywhere,
 * not even this file's own original body). `IN_ADMIN` is genuinely read
 * elsewhere (Piwigo\Page\PageHeaderRenderer) so it stays, set by the new
 * bootstrap file the same way admin.php itself sets it.
 *
 * Workstream C3c: converted off LegacyRenderCapture's ob_start()/
 * ob_get_contents() capture -- same mechanism/reasoning as
 * PopuphelpController's own docblock (parse($handle, false) accumulates
 * into Template's own buffer instead of echoing, PageTail::
 * renderToString() drains it as one string). The `?page=` validation
 * throws ResponseReadyException instead of die()ing mid-render (same
 * "no partial HTML left to preserve any more" reasoning as
 * PopuphelpController); the `output=content_only` branch just returns
 * $help_content directly -- by that point in the method it's already
 * fully computed, nothing left to accumulate.
 */
final class AdminPopuphelpController implements ControllerInterface
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
        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;
        $output = $queryParams['output'] ?? null;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no
        // other file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        if ($output !== 'content_only') {
            $this->pageState->setBodyId('thePopuphelpPage');
            $title = $this->lang->t('Piwigo Help');
            $this->pageState->setPageBanner('<h1>' . $title . '</h1>');
            $this->pageState->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);

            // set required template variables to avoid "Undefined array key" with PHP 8
            $template->assign(
                [
                    'U_RETURN' => '',
                    'USERNAME' => '',
                    'U_FAQ' => '',
                    'U_CHANGE_THEME' => '',
                    'U_LOGOUT' => '',
                ]
            );

            new PageHeaderRenderer()
                ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        }

        if (! is_string($rawPage) || ! (bool) preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new ResponseReadyException(ResponseFactory::text('Hacking attempt!', 400));
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

        $help_content = $this->eventDispatcher->dispatchChange(new GetPopupHelpContent($help_content, $rawPage))
            ->content;

        $template->set_filename('popuphelp', 'popuphelp.tpl');
        $template->assign(
            [
                'HELP_CONTENT' => $help_content,
            ]
        );

        if ($output === 'content_only') {
            return ResponseFactory::html($help_content);
        }

        $template->parse('popuphelp', false);

        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
