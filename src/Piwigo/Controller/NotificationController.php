<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedRepository;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\Template;
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
        check_status(AccessLevel::Guest);

        trigger_notify('loc_begin_notification');

        $feedRepo = new FeedRepository(DbConnection::build());
        $feedId = $this->findAvailableFeedId($feedRepo);

        $body = LegacyRenderCapture::capture(static function () use ($feedRepo, $feedId): void {
            /**
             * @var array<string, mixed> $page
             * @var Template $template
             * @var array<string, mixed> $user
             */
            global $page, $template, $user, $title;

            $page['feed'] = $feedId;

            // $user['id'] (the logged in / guest user id) is never
            // reassigned below.
            $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

            $feedRepo->insert($feedId, $user_id);

            $feed_url = PHPWG_ROOT_PATH . 'feed.php';
            if (is_a_guest()) {
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
                include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
            }

            include PHPWG_ROOT_PATH . 'include/page_header.php';
            trigger_notify('loc_end_notification');
            flush_page_messages();
            $template->pparse('notification');
            include PHPWG_ROOT_PATH . 'include/page_tail.php';
        });

        return ResponseFactory::html($body);
    }

    private function findAvailableFeedId(FeedRepository $feedRepo): string
    {
        while (true) {
            $key = generate_key(50);
            if (! $feedRepo->existsById($key)) {
                return $key;
            }
        }
    }
}
