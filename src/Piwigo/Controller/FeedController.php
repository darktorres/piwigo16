<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use DateTimeImmutable;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedHelper;
use Piwigo\Feed\FeedRepository;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces feed.php -- generates the RSS2 gallery feed. Unlike every other
 * P22 controller, this page never touches Template/page_header.php/
 * page_tail.php at all (it's a plain XML response, not an HTML page), so
 * there's no LegacyRenderCapture involved -- the whole body is built as a
 * plain string and returned via ResponseFactory::raw() with the feed's own
 * Content-Type/Content-Disposition headers.
 *
 * page_not_found() happens before any output is built, so it's fine to
 * leave outside of any capture -- same exit()-based-termination limitation
 * as every other controller this phase.
 */
final class FeedController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        include_once PHPWG_ROOT_PATH . 'include/functions_notification.inc.php';

        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        $feed_helper = new FeedHelper();
        $feed_repo = new FeedRepository(DbConnection::build());

        check_input_parameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

        $feed_id = $_GET['feed'] ?? '';
        $feed_id = is_string($feed_id) ? $feed_id : '';
        $image_only = isset($_GET['image_only']);
        // Only read below when $image_only is false, which implies $feed_id
        // was non-empty and the branch below already populated it.
        $feed_last_check = null;

        if ($feed_id !== '') {
            $feed_row = $feed_repo->findById($feed_id);
            if ($feed_row === null) {
                page_not_found(l10n('Unknown feed identifier'));
            }
            $feed_last_check = $feed_row['lastCheck'];
            $user_id_before = is_numeric($user['id']) ? (int) $user['id'] : null;
            if ($feed_row['userId'] !== $user_id_before) { // new user
                $user = build_user($feed_row['userId'], true);
            }
        } else {
            $image_only = true;
            if (! is_a_guest()) {// auto session was created - so switch to guest
                $guest_id = $conf['guest_id'];
                $guest_id = is_numeric($guest_id) ? (int) $guest_id : 0;
                $user = build_user($guest_id, true);
            }
        }

        // Check the status now after the user has been loaded
        check_status(AccessLevel::Guest);

        $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        assert($row !== null);
        [$dbnow] = $row;
        // NOW() is a MySQL builtin that always returns a valid non-null
        // datetime value, never a nullable column read.
        assert(is_string($dbnow));

        set_make_full_url();

        $rss_encoding = get_pwg_charset();

        // $conf/$user are typed array<string, mixed> (see the global
        // declaration above); each of these values is read again later in
        // this method, so narrow once here and reuse the local variable at
        // every later read.
        $conf_gallery_title = $conf['gallery_title'] ?? '';
        $conf_gallery_title = is_string($conf_gallery_title) ? $conf_gallery_title : '';
        $conf_rss_feed_author = $conf['rss_feed_author'] ?? '';
        $conf_rss_feed_author = is_string($conf_rss_feed_author) ? $conf_rss_feed_author : '';
        $user_username = $user['username'] ?? '';
        $user_username = is_string($user_username) ? $user_username : '';

        $rss_title = $conf_gallery_title . ' (as ' . stripslashes($user_username) . ')';
        $rss_link = get_gallery_home_url();
        $rss_items = [];

        // +-------------------------------------------------------------------+
        // |                            Feed creation                          |
        // +-------------------------------------------------------------------+

        $news = [];
        if (! $image_only) {
            $news = news($feed_last_check?->format('Y-m-d H:i:s'), $dbnow, true, true);

            if (count($news) > 0) {
                // content creation
                $description = '<ul>';
                foreach ($news as $line) {
                    $description .= '<li>' . $line . '</li>';
                }
                $description .= '</ul>';

                $dbnow_ts = $feed_helper->datetimeToTs($dbnow);
                // $dbnow is a NOW() value straight from the database,
                // always a valid datetime string
                assert($dbnow_ts !== false);

                $rss_items[] = [
                    'title' => l10n('New on %s', format_date($dbnow)),
                    'link' => get_gallery_home_url(),
                    'description' => $description,
                    'html' => true,
                    'date' => $feed_helper->ts8601($dbnow_ts),
                    'author' => $conf_rss_feed_author,
                    'guid' => sprintf('%s', $dbnow),
                ];

                if ($feed_id !== '') {
                    $feed_repo->updateLastCheck($feed_id, new DateTimeImmutable($dbnow));
                }
            }
        }

        if ($feed_id !== '' && count($news) === 0) {// update the last check from time to time to avoid deletion by maintenance tasks
            if ($feed_last_check === null
              || time() - $feed_last_check->getTimestamp() > 30 * 24 * 3600) {
                // SUBDATE($dbnow, INTERVAL -15 DAY) computed in PHP instead
                // -- cross-provider safe (SUBDATE() is MySQL-only), same
                // reasoning as FeedRepository::updateLastCheck()'s own doc
                // comment.
                $feed_repo->updateLastCheck($feed_id, new DateTimeImmutable($dbnow)->modify('+15 days'));
            }
        }

        // recent_post_dates is a config array of per-notification-kind int
        // settings (see include/config_default.inc.php); narrow the 'RSS'
        // entry to the array<string, int> shape
        // get_recent_post_dates_array() expects.
        $recent_post_dates_conf = $conf['recent_post_dates'];
        $rss_recent_post_dates_raw = (is_array($recent_post_dates_conf) and is_array($recent_post_dates_conf['RSS'] ?? null))
            ? $recent_post_dates_conf['RSS']
            : [];
        $rss_recent_post_dates_args = [];
        foreach ($rss_recent_post_dates_raw as $arg_key => $arg_value) {
            if (is_string($arg_key) and is_int($arg_value)) {
                $rss_recent_post_dates_args[$arg_key] = $arg_value;
            }
        }

        /** @var array<int, array<string, mixed>> $dates */
        $dates = get_recent_post_dates_array($rss_recent_post_dates_args);

        foreach ($dates as $date_detail) { // for each recent post date we create a feed item
            $date = $date_detail['date_available'];
            // date_available is a NOT NULL datetime column, always a
            // string.
            assert(is_string($date));
            $link = make_index_url(
                [
                    'chronology_field' => 'posted',
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'calendar',
                    'chronology_date' => explode('-', substr($date, 0, 10)),
                ]
            );

            $description = '<a href="' . make_index_url() . '">' . $conf_gallery_title . '</a><br> ';
            $description .= get_html_description_recent_post_date($date_detail);

            $date_ts = $feed_helper->datetimeToTs($date);
            // $date is a date_available value straight from the database,
            // always a valid datetime string
            assert($date_ts !== false);

            $rss_items[] = [
                'title' => get_title_recent_post_date($date_detail),
                'link' => $link,
                'description' => $description,
                'html' => true,
                'date' => $feed_helper->ts8601($date_ts),
                'author' => $conf_rss_feed_author,
                'guid' => sprintf('%s', 'pics-' . $date),
            ];
        }

        $feed_content = $feed_helper->generateRss2Feed(
            [
                'title' => $rss_title,
                'link' => $rss_link,
                'encoding' => $rss_encoding,
            ],
            $rss_items
        );

        // $conf['data_location'] needs narrowing here specifically (used to
        // build the on-disk tmp path this method writes the generated feed
        // to).
        $data_location = $conf['data_location'];
        if (! is_string($data_location)) {
            die("Invalid \$conf['data_location'] configuration: expected a string.");
        }

        $fileName = PHPWG_ROOT_PATH . $data_location . 'tmp';
        mkgetdir($fileName); // just in case
        $fileName .= '/feed.xml';
        file_put_contents($fileName, $feed_content);

        // send XML feed
        return ResponseFactory::raw($feed_content, [
            'Content-Type' => 'application/rss+xml; charset=' . $rss_encoding . '; filename=' . basename($fileName),
            'Content-Disposition' => 'inline; filename=' . basename($fileName),
        ]);
    }
}
