<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Exception\AuthException;
use Piwigo\Http\ResponseFactory;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Core\AccessLevel;

final class PopuphelpController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Guest);

        if (!defined('PWG_HELP')) {
            define('PWG_HELP', true);
        }

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $title               = l10n('Piwigo Help');
        $page['body_id']     = 'thePopuphelpPage';
        $page['page_banner'] = '';
        $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];

        $rawPage  = is_string($_GET['page'] ?? null) ? $_GET['page'] : null;
        if ($rawPage === null || !preg_match('/^[a-z_]*$/', $rawPage)) {
            throw new AuthException('Hacking attempt!');
        }

        $helpContent = load_language('help/' . $rawPage . '.html', '', ['return' => true]);
        if ($helpContent === false) {
            $helpContent = '';
        }
        $filtered = EventDispatcher::dispatch('get_popup_help_content', $helpContent, $rawPage);
        $helpContent = is_string($filtered) ? $filtered : $helpContent;

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['popuphelp' => 'popuphelp.tpl']);
        $tpl->assign(['HELP_CONTENT' => $helpContent]);

        PageHeaderRenderer::render($title);
        $tpl->pparse('popuphelp');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
