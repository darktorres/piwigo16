<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Exception\AuthException;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PopuphelpController implements ControllerInterface
{
    public function __construct(
        private PermissionService $permissionService,
        private LangService $langService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);

        if (!defined('PWG_HELP')) {
            define('PWG_HELP', true);
        }

        $title = Lang::t('Piwigo Help');
        $ps = PageState::current();
        $ps->bodyId     = 'thePopuphelpPage';
        $ps->pageBanner = '';
        $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];

        $getPage  = $_GET['page'] ?? null;
        $rawPage  = is_string($getPage) ? $getPage : null;
        if ($rawPage === null || !preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new AuthException('Hacking attempt!');
        }

        $loaded = $this->langService->loadLanguage('help/' . $rawPage . '.html', '', ['return' => true]);
        $helpContent = is_string($loaded) ? $loaded : '';
        $helpContent = EventDispatcher::dispatch('get_popup_help_content', $helpContent, $rawPage);

        $tpl = TemplateRegistry::current();
        $tpl->assign(['HELP_CONTENT' => new Html($helpContent)]);

        PageHeaderRenderer::render($title);
        $tpl->pparse('popuphelp.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
