<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
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

final class PopuphelpController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Guest);

        if (!defined('PWG_HELP')) {
            define('PWG_HELP', true);
        }

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $title               = Lang::t('Piwigo Help');
        $page['body_id']     = 'thePopuphelpPage';
        $page['page_banner'] = '';
        $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];

        $getPage  = $_GET['page'] ?? null;
        $rawPage  = is_string($getPage) ? $getPage : null;
        if ($rawPage === null || !preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new AuthException('Hacking attempt!');
        }

        $helpContent = LangService::get()->loadLanguage('help/' . $rawPage . '.html', '', ['return' => true]);
        if ($helpContent === false) {
            $helpContent = '';
        }
        $filtered = EventDispatcher::dispatch('get_popup_help_content', $helpContent, $rawPage);
        $helpContent = is_string($filtered) ? $filtered : $helpContent;

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['popuphelp' => 'popuphelp.latte']);
        $tpl->assign(['HELP_CONTENT' => new Html($helpContent)]);

        PageHeaderRenderer::render($title);
        $tpl->pparse('popuphelp');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
