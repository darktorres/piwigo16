<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Exception\AuthException;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PopuphelpController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_GUEST);

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
        $filtered = trigger_change('get_popup_help_content', $helpContent, $rawPage);
        $helpContent = is_string($filtered) ? $filtered : $helpContent;

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['popuphelp' => 'popuphelp.tpl']);
        $tpl->assign(['HELP_CONTENT' => $helpContent]);

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        $tpl->pparse('popuphelp');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
