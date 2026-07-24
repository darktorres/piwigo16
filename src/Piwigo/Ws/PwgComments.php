<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\Url\UrlService;

/**
 * P23 batch 8e-2: relocated from include/ws_functions/pwg.comments.php.
 * `pwg.userComments.*` WS methods (3 registrations, all admin_only) --
 * registered via callable arrays in include/ws_default_methods.inc.php.
 */
final class PwgComments
{
    /**
     * API method
     * Get comments
     * @since 16
     *
     * @param array{status: string, search: string|null, author_id?: int, image_id?: int, f_min_date: string|null, f_max_date: string|null, page: int, per_page: int, ...} $params
     *   status: non-null string default ('all'), no 'type' flag -- always
     *   present. search/f_min_date/f_max_date: null default, no 'type' flag
     *   -- always present, string|null. author_id/image_id: WsParamFlag::OPTIONAL
     *   with no 'default' key -- may be entirely absent; WsParamType::ID
     *   guarantees a plain int when present. page: non-null int default,
     *   WsParamType::INT|WsParamType::POSITIVE -- always present. per_page: same type
     *   flag, default is \Piwigo\Config\Config::commentsPageNbComments() (a real int,
     *   confirmed 10 in config_default.inc.php) -- always present, always
     *   int.
     * @return PwgError|array<string, mixed>
     */
    public static function getList(array $params, PwgServer &$service): PwgError|array
    {
        $conn = DbConnection::build();

        if (! \Piwigo\Config\Config::activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }

        // accepted status values
        $accepted_status = ['all', 'pending', 'validated'];
        if (! in_array($params['status'], $accepted_status, true)) {
            return new PwgError(401, 'Status must be: all, pending or validated');
        }

        // accepted values must match pagination options (5,10,25,50)
        $items_number = [5, 10, 25, 50];
        if (! in_array($params['per_page'], $items_number, true)) {
            return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
        }

        $where_clauses = ['1=1'];

        if (isset($params['author_id']) and $params['author_id'] !== 0) {
            $where_clauses['author_id'] = 'author_id = ' . $params['author_id'];
        }

        if (isset($params['image_id']) and $params['image_id'] !== 0) {
            $where_clauses[] = 'image_id = ' . $params['image_id'];
        }

        if (! in_array($params['f_min_date'], [null, ''], true)) {
            $min_date = date_create($params['f_min_date']);
            if ($min_date === false) {
                return new PwgError(401, 'Invalid f_min_date');
            }
            $min = date_format($min_date, 'Y-m-d 00:00:00');
            $where_clauses[] = 'date >= \'' . $min . '\'';
        }

        if (! in_array($params['f_max_date'], [null, ''], true)) {
            $max_date = date_create($params['f_max_date']);
            if ($max_date === false) {
                return new PwgError(401, 'Invalid f_max_date');
            }
            $max = date_format($max_date, 'Y-m-d 23:59:59');
            $where_clauses[] = 'date <= \'' . $max . '\'';
        }

        // reset all filters during search
        if (! in_array($params['search'], [null, ''], true)) {
            $where_clauses = ['1=1'];
            // [SEC-18] real DBAL driver escaping via Connection::quote()
            // (includes its own surrounding quotes), replacing the
            // original's MysqliDb::realEscapeString() -- same "free-text
            // term spliced into a larger hand-built WHERE fragment"
            // rationale as Search\SearchRepository::quote().
            $where_clauses[] = 'content LIKE ' . $conn->quote('%' . $params['search'] . '%');
        }

        // summary. validated is a real tinyint(1) column now (Comment
        // domain Stage 1a) -- numeric literals, not the old
        // enum('true','false') strings; MySQL's non-numeric-string-to-int
        // coercion would otherwise silently convert 'true' to 0 too,
        // inverting the validated/pending counts (same bug class
        // Category's own commentable/visible retype found).
        $query = '
SELECT
  count(*) as all_comments,
  sum(validated = 1) as validated,
  sum(validated = 0) as pending
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $where_clauses) . '
;';

        $summary = $conn->fetchAssociative($query);
        if (! is_array($summary)) {
            return new PwgError(500, 'Unable to compute comments summary');
        }
        // count(*)/sum(...) results are typed string|null by the driver; they
        // are always numeric here (count/sum of a non-empty aggregate), but
        // fall back to 0 rather than assume it.
        $total_comments = is_numeric($summary['all_comments']) ? (int) $summary['all_comments'] : 0;

        switch ($params['status']) {
            case 'pending':
                $where_clauses[] = 'validated = 0';
                $total_comments = is_numeric($summary['pending']) ? (int) $summary['pending'] : 0;
                break;

            case 'validated':
                $where_clauses[] = 'validated = 1';
                $total_comments = is_numeric($summary['validated']) ? (int) $summary['validated'] : 0;
                break;
        }

        // comments
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();
        $query = '
SELECT
    c.id,
    c.image_id,
    c.date,
    c.author,
    c.author_id,
    ' . $user_fields['username'] . ' AS username,
    ui.status,
    c.content,
    i.path,
    i.representative_ext,
    i.file,
    i.date_available,
    validated,
    c.anonymous_id
  FROM ' . Tables::comments() . ' AS c
    INNER JOIN ' . Tables::images() . ' AS i
      ON i.id = c.image_id
    LEFT JOIN ' . Tables::users() . ' AS u
      ON u.' . $user_fields['id'] . ' = c.author_id
    LEFT JOIN ' . Tables::userInfos() . ' AS ui
      ON ui.user_id = c.author_id
  WHERE ' . implode(' AND ', $where_clauses) . '
  ORDER BY c.date DESC, c.id DESC
  LIMIT ' . $params['per_page'] * $params['page'] . ', ' . $params['per_page'] . '
;';
        $list = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {

            $row_image_id = $row['image_id'];

            $medium_derivative = DerivativeImage::get_one(
                ImageStdParams::MEDIUM,
                [
                    'id' => $row_image_id,
                    'path' => $row['path'],
                    'representative_ext' => $row['representative_ext'],
                ]
            );
            // MEDIUM is a standard type, always present in the defined
            // type map — get_one() only returns null for an unknown type.
            assert($medium_derivative instanceof DerivativeImage);
            $medium = $medium_derivative->get_url();

            $row_author = is_string($row['author']) ? $row['author'] : null;
            if (! is_numeric($row['author_id']) or (int) $row['author_id'] === 0 or (int) $row['author_id'] === \Piwigo\Config\Config::guestId()) {
                $author_name = $row_author;
            } else {
                $row_username = $row['username'] ?? null;
                $author_name = stripslashes((is_string($row_username) ? $row_username : null) ?? $row_author ?? Lang::t('guest'));
            }

            // date/date_available are NOT NULL DATETIME columns -- always
            // string under both the legacy mysqli driver and DBAL (native
            // int/float casting only applies to INT/DECIMAL/FLOAT columns,
            // never temporal ones); format_date()'s phpDoc param forbids
            // null, so fall back to false (its "no date" sentinel) if that
            // ever isn't the case.
            $comment_date = is_string($row['date']) ? $row['date'] : false;
            $comment_date_available = is_string($row['date_available']) ? $row['date_available'] : false;

            $list[] = [
                'id' => $row['id'],
                'admin_link' => new UrlService(new HtmlService())
                    ->getRootUrl() . 'admin.php?page=photo-' . (is_scalar($row_image_id) ? (string) $row_image_id : ''),
                'medium_url' => $medium,
                'file' => $row['file'],
                'image_date_available' => \Piwigo\Core\DateHelper::formatDate($comment_date_available, ['day_name', 'day', 'month', 'year', 'time']),
                'author' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_author', $author_name),
                'author_status' => is_numeric($row['author_id']) && \Piwigo\Config\Config::webmasterId() === (int) $row['author_id'] ? 'main_user' : $row['status'],
                'date' => \Piwigo\Core\DateHelper::formatDate($comment_date, ['day_name', 'day', 'month', 'year', 'time']),
                'content' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_content', $row['content']),
                'raw_content' => $row['content'],
                'is_pending' => ! SqlDialect::getBoolean($row['validated']),
            ];
        }

        // filters
        $query = '
SELECT
  MIN(date) AS started_at,
  MAX(date) AS ended_at
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $where_clauses) . '
;';

        $dates = $conn->fetchAssociative($query);
        if (! is_array($dates)) {
            return new PwgError(500, 'Unable to compute comments date range');
        }

        unset($where_clauses['author_id']);
        // ANY_VALUE(author): `author` isn't functionally dependent on the
        // GROUP BY column (author_id) -- Piwigo\Db\DbConnection doesn't
        // strip ONLY_FULL_GROUP_BY the way the legacy mysqli connection
        // did, so this query (already GROUP BY author_id, unchanged from
        // the original) needs an explicit "any value is fine, same as the
        // old driver's own permissive default" opt-out to keep selecting
        // exactly one arbitrary author name per author_id, matching the
        // original grouping/row-count exactly rather than splitting groups
        // by adding author to GROUP BY.
        $query = '
SELECT
  ANY_VALUE(author) AS author,
  author_id,
  count(*) as nb_authors
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $where_clauses) . '
GROUP BY author_id
;';

        $nb_authors_in = $conn->fetchAllAssociative($query);

        return [
            'summary' => $summary,
            'comments' => $list,
            'filters' => [
                'nb_authors' => $nb_authors_in,
                'started_at' => $dates['started_at'],
                'ended_at' => $dates['ended_at'],
            ],
            'paging' => [
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'total_pages' => max(0.0, ceil((float) $total_comments / (float) $params['per_page']) - 1.0),
            ],
        ];
    }

    /**
     * API method
     * Delete comments
     * @since 16
     *
     * @param array{comment_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces comment_id to a list of positive ints.
     */
    public static function delete(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }

        $params['comment_id'] = array_unique($params['comment_id']);
        self::commentService()->deleteComment($params['comment_id']);
        return 'Comment successfully deleted';
    }

    /**
     * API method
     * Validate comments
     * @since 16
     *
     * @param array{comment_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces comment_id to a list of positive ints.
     */
    public static function validate(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }

        $params['comment_id'] = array_unique($params['comment_id']);
        self::commentService()->validateComment($params['comment_id']);
        return 'Comment successfully validated';
    }

    /**
     * Constructed identically in delete()/validate() -- both static
     * methods, no shared instance state to inject into, same
     * "private static helper" precedent as
     * Bootstrap\RequestBootstrap::activityService().
     */
    private static function commentService(): CommentService
    {
        return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService(), new MailService(), new HtmlService(), new UrlService(new HtmlService()));
    }
}
