<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces notification.php -- mints a new per-user feed subscription and
 * shows its URL. check_status() stays outside the captured closure, same
 * exit()-based-termination limitation as every other controller this
 * phase. The legacy file's own top-level find_available_feed_id() becomes
 * a private method here instead of a free function -- only this
 * controller ever called it, and a bare global function risks the same
 * name-collision class of bug P21's Extensions batch found (two admin
 * pages both declaring `function cmp()`).
 */
final class NotificationController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        trigger_notify('loc_begin_notification');

        $feedRepo = new FeedRepository(DbConnection::build());
        $feedId = $this->findAvailableFeedId($feedRepo);

        $body = LegacyRenderCapture::capture(static function () use ($feedRepo, $feedId): void {
            /**
             * @var array<string, mixed> $page
             * @var array<string, mixed> $user
             */
            global $page, $user, $title;
            $template = \Piwigo\Template\CurrentTemplate::get();

            $page['feed'] = $feedId;

            // $user['id'] (the logged in / guest user id) is never
            // reassigned below.
            $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

            $feedRepo->insert($feedId, $user_id);

            $feed_url = PHPWG_ROOT_PATH . 'feed.php';
            if (\Piwigo\Auth\AccessControl::isAGuest()) {
                $feed_image_only_url = $feed_url;
                $feed_url .= '?feed=' . $feedId;
            } else {
                $feed_url .= '?feed=' . $feedId;
                $feed_image_only_url = $feed_url . '&amp;image_only';
            }

            $title = l10n('Notification');
            $page['body_id'] = 'theNotificationPage';
            $page['meta_robots'] = [
                'noindex' => 1,
                'nofollow' => 1,
            ];

            $template->set_filenames([
                'notification' => 'notification.tpl',
            ]);

            $template->assign(
                [
                    'U_FEED' => $feed_url,
                    'U_FEED_IMAGE_ONLY' => $feed_image_only_url,
                ]
            );

            $themeconf = $template->get_template_vars('themeconf');
            $themeconf = is_array($themeconf) ? $themeconf : [];
            if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theNotificationPage', $themeconf['hide_menu_on'], true)) {
                new MenubarRenderer()
                    ->render();
            }

            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            trigger_notify('loc_end_notification');
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('notification');
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }

    private function findAvailableFeedId(FeedRepository $feedRepo): string
    {
        while (true) {
            $key = SessionService::get()->generateKey(50);
            if (! $feedRepo->existsById($key)) {
                return $key;
            }
        }
    }
}
