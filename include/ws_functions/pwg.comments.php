<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Get comments
 * @since 16
 *
 * @param array{status: string, search: string|null, author_id?: int, image_id?: int, f_min_date: string|null, f_max_date: string|null, page: int, per_page: int, ...} $params
 *   status: non-null string default ('all'), no 'type' flag -- always
 *   present. search/f_min_date/f_max_date: null default, no 'type' flag
 *   -- always present, string|null. author_id/image_id: WS_PARAM_OPTIONAL
 *   with no 'default' key -- may be entirely absent; WS_TYPE_ID
 *   guarantees a plain int when present. page: non-null int default,
 *   WS_TYPE_INT|WS_TYPE_POSITIVE -- always present. per_page: same type
 *   flag, default is $conf['comments_page_nb_comments'] (a real int,
 *   confirmed 10 in config_default.inc.php) -- always present, always
 *   int.
 * @return \PwgError|array<string, mixed>
 */
function ws_userComments_getList(array $params, PwgServer &$service): \PwgError|array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! $conf['activate_comments']) {
        return new PwgError(403, 'Comments are disabled');
    }

    // accepted status values
    $accepted_status = ['all', 'pending', 'validated'];
    if (! in_array($params['status'], $accepted_status)) {
        return new PwgError(401, 'Status must be: all, pending or validated');
    }

    // accepted values must match pagination options (5,10,25,50)
    $items_number = [5, 10, 25, 50];
    if (! in_array($params['per_page'], $items_number)) {
        return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
    }

    $where_clauses = ['1=1'];

    if (isset($params['author_id']) and ! empty($params['author_id'])) {
        $where_clauses['author_id'] = 'author_id = ' . $params['author_id'];
    }

    if (isset($params['image_id']) and ! empty($params['image_id'])) {
        $where_clauses[] = 'image_id = ' . $params['image_id'];
    }

    if (! empty($params['f_min_date'])) {
        $min_date = date_create($params['f_min_date']);
        if ($min_date === false) {
            return new PwgError(401, 'Invalid f_min_date');
        }
        $min = date_format($min_date, 'Y-m-d 00:00:00');
        $where_clauses[] = 'date >= \'' . $min . '\'';
    }

    if (! empty($params['f_max_date'])) {
        $max_date = date_create($params['f_max_date']);
        if ($max_date === false) {
            return new PwgError(401, 'Invalid f_max_date');
        }
        $max = date_format($max_date, 'Y-m-d 23:59:59');
        $where_clauses[] = 'date <= \'' . $max . '\'';
    }

    // reset all filters during search
    if (! empty($params['search'])) {
        $where_clauses = ['1=1'];
        $where_clauses[] = 'content LIKE "%' . pwg_db_real_escape_string($params['search']) . '%"';
    }

    // summary
    $query = '
SELECT
  count(*) as all_comments,
  sum(validated = \'true\') as validated,
  sum(validated = \'false\') as pending
FROM ' . COMMENTS_TABLE . '
WHERE ' . implode(' AND ', $where_clauses) . '
;';

    $summary = pwg_db_fetch_assoc(pwg_query($query));
    if (! is_array($summary)) {
        return new PwgError(500, 'Unable to compute comments summary');
    }
    // count(*)/sum(...) results are typed string|null by the driver; they
    // are always numeric here (count/sum of a non-empty aggregate), but
    // fall back to 0 rather than assume it.
    $total_comments = is_numeric($summary['all_comments']) ? (int) $summary['all_comments'] : 0;

    switch ($params['status']) {
        case 'pending':
            $where_clauses[] = 'validated = \'false\'';
            $total_comments = is_numeric($summary['pending']) ? (int) $summary['pending'] : 0;
            break;

        case 'validated':
            $where_clauses[] = 'validated = \'true\'';
            $total_comments = is_numeric($summary['validated']) ? (int) $summary['validated'] : 0;
            break;
    }

    // comments
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];
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
  FROM ' . COMMENTS_TABLE . ' AS c
    INNER JOIN ' . IMAGES_TABLE . ' AS i
      ON i.id = c.image_id
    LEFT JOIN ' . USERS_TABLE . ' AS u
      ON u.' . $user_fields['id'] . ' = c.author_id
    LEFT JOIN ' . USER_INFOS_TABLE . ' AS ui
      ON ui.user_id = c.author_id
  WHERE ' . implode(' AND ', $where_clauses) . '
  ORDER BY c.date DESC
  LIMIT ' . $params['per_page'] * $params['page'] . ', ' . $params['per_page'] . '
;';
    $result = pwg_query($query);

    $list = [];
    while ($row = pwg_db_fetch_assoc($result)) {

        $medium_derivative = DerivativeImage::get_one(
            IMG_MEDIUM,
            [
                'id' => $row['image_id'],
                'path' => $row['path'],
                'representative_ext' => $row['representative_ext'],
            ]
        );
        // IMG_MEDIUM is a standard type, always present in the defined
        // type map — get_one() only returns null for an unknown type.
        assert($medium_derivative instanceof \DerivativeImage);
        $medium = $medium_derivative->get_url();

        if (empty($row['author_id']) or $row['author_id'] == $conf['guest_id']) {
            $author_name = $row['author'];
        } else {
            $author_name = stripslashes((string) ($row['username'] ?? $row['author'] ?? l10n('guest')));
        }

        // date/date_available are NOT NULL columns but the driver still
        // types every fetched value as string|null; format_date()'s
        // phpDoc param forbids null, so fall back to false (its "no date"
        // sentinel) if that ever isn't the case.
        $comment_date = is_string($row['date']) ? $row['date'] : false;
        $comment_date_available = is_string($row['date_available']) ? $row['date_available'] : false;

        $list[] = [
            'id' => $row['id'],
            'admin_link' => get_root_url() . 'admin.php?page=photo-' . $row['image_id'],
            'medium_url' => $medium,
            'file' => $row['file'],
            'image_date_available' => format_date($comment_date_available, ['day_name', 'day', 'month', 'year', 'time']),
            'author' => trigger_change('render_comment_author', $author_name),
            'author_status' => $conf['webmaster_id'] == $row['author_id'] ? 'main_user' : $row['status'],
            'date' => format_date($comment_date, ['day_name', 'day', 'month', 'year', 'time']),
            'content' => trigger_change('render_comment_content', $row['content']),
            'raw_content' => $row['content'],
            'is_pending' => ($row['validated'] == 'false'),
        ];
    }

    // filters
    $query = '
SELECT
  MIN(date) AS started_at,
  MAX(date) AS ended_at
FROM ' . COMMENTS_TABLE . '
WHERE ' . implode(' AND ', $where_clauses) . '
;';

    $dates = pwg_db_fetch_assoc(pwg_query($query));
    if (! is_array($dates)) {
        return new PwgError(500, 'Unable to compute comments date range');
    }

    unset($where_clauses['author_id']);
    $query = '
SELECT
  author,
  author_id,
  count(*) as nb_authors
FROM ' . COMMENTS_TABLE . '
WHERE ' . implode(' AND ', $where_clauses) . '
GROUP BY author_id
;';

    $nb_authors_in = query2array($query);

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
            'total_pages' => max(0, ceil($total_comments / $params['per_page']) - 1),
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
function ws_userComments_delete(array $params, PwgServer &$service): \PwgError|string
{
    include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    $params['comment_id'] = array_unique($params['comment_id']);
    delete_user_comment($params['comment_id']);
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
function ws_userComments_validate(array $params, PwgServer &$service): \PwgError|string
{
    include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    $params['comment_id'] = array_unique($params['comment_id']);
    validate_user_comment($params['comment_id']);
    return 'Comment successfully validated';
}
