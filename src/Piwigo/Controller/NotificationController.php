<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\ServiceLocator;
use Piwigo\Feed\FeedRepository;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the notification/RSS subscription page (/notification).
 * Corresponds to the former notification.php entry-point.
 */
final class NotificationController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_GUEST);

        trigger_notify('loc_begin_notification');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $page['feed'] = $this->findAvailableFeedId();

        ServiceLocator::get(FeedRepository::class)
            ->insert((string) $page['feed'], is_numeric($user['id']) ? (int) $user['id'] : 0);

        $feed_url = ServiceLocator::get(UrlGenerator::class)->feed();
        if (is_a_guest()) {
            $feed_image_only_url = $feed_url;
            $feed_url .= '?feed=' . $page['feed'];
        } else {
            $feed_url .= '?feed=' . $page['feed'];
            $feed_image_only_url = $feed_url . '&amp;image_only';
        }

        $title = l10n('Notification');
        $page['body_id'] = 'theNotificationPage';
        $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['notification' => 'notification.tpl']);
        $tpl->assign(['U_FEED' => $feed_url, 'U_FEED_IMAGE_ONLY' => $feed_image_only_url]);

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theNotificationPage', $hideMenuOn)) {
            require PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        trigger_notify('loc_end_notification');
        flush_page_messages();
        $tpl->pparse('notification');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }

    private function findAvailableFeedId(): string
    {
        $feedRepo = ServiceLocator::get(FeedRepository::class);
        while (true) {
            $key = generate_key(50);
            if (!$feedRepo->existsById($key)) {
                return $key;
            }
        }
    }
}
