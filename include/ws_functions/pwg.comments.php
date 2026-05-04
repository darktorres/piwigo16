<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Ws\PwgError;

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
 * @param mixed[] $params
 *
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 */
function ws_userComments_getList(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    if (!\Piwigo\Config\Config::activateComments()) {
        return new PwgError(403, 'Comments are disabled');
    }

    // accepted status values
    $accepted_status = ['all', 'pending', 'validated'];
    if (!in_array($params['status'], $accepted_status)) {
        return new PwgError(401, 'Status must be: all, pending or validated');
    }

    // accepted values must match pagination options (5,10,25,50)
    $items_number = [5, 10, 25, 50];
    if (!in_array($params['per_page'], $items_number)) {
        return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
    }

    $where_clauses = ['1=1'];

    if (isset($params['author_id']) and !empty($params['author_id'])) {
        $where_clauses['author_id'] = 'author_id = \''. pwg_db_real_escape_string(is_scalar($params['author_id']) ? (string) $params['author_id'] : '') .'\'';
    }

    if (isset($params['image_id']) and !empty($params['image_id'])) {
        $where_clauses[] = 'image_id = \''. pwg_db_real_escape_string(is_scalar($params['image_id']) ? (string) $params['image_id'] : '') .'\'';
    }

    if (!empty($params['f_min_date'])) {
        $dmin = date_create(is_scalar($params['f_min_date']) ? (string) $params['f_min_date'] : '');
        if ($dmin !== false) {
            $where_clauses[] = 'date >= \''. date_format($dmin, 'Y-m-d 00:00:00') .'\'';
        }
    }

    if (!empty($params['f_max_date'])) {
        $dmax = date_create(is_scalar($params['f_max_date']) ? (string) $params['f_max_date'] : '');
        if ($dmax !== false) {
            $where_clauses[] = 'date <= \''. date_format($dmax, 'Y-m-d 23:59:59') .'\'';
        }
    }

    // reset all filters during search
    if (!empty($params['search'])) {
        $where_clauses = ['1=1'];
        $where_clauses[] = 'content LIKE "%'. pwg_db_real_escape_string(is_scalar($params['search']) ? (string) $params['search'] : '') .'%"';
    }

    // summary
    $query = '
SELECT
  count(*) as all_comments,
  sum(validated = \'true\') as validated,
  sum(validated = \'false\') as pending
FROM '.COMMENTS_TABLE.'
WHERE '.implode(' AND ', $where_clauses).'
;';

    $summary = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)->fetchAssociative() ?: [];
    $total_comments = $summary['all_comments'] ?? null;

    switch ($params['status']) {
        case 'pending':
            $where_clauses[] = 'validated = \'false\'';
            $total_comments = $summary['pending'] ?? null;
            break;

        case 'validated':
            $where_clauses[] = 'validated = \'true\'';
            $total_comments = $summary['validated'] ?? null;
            break;
    }

    $per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 10;
    $page_num = is_numeric($params['page']) ? (int) $params['page'] : 0;

    // comments
    $query = '
SELECT
    c.id,
    c.image_id,
    c.date,
    c.author,
    c.author_id,
    '.\Piwigo\Config\Config::userFields()['username'].' AS username,
    ui.status,
    c.content,
    i.path,
    i.representative_ext,
    i.file,
    i.date_available,
    validated,
    c.anonymous_id
  FROM '.COMMENTS_TABLE.' AS c
    INNER JOIN '.IMAGES_TABLE.' AS i
      ON i.id = c.image_id
    LEFT JOIN '.USERS_TABLE.' AS u
      ON u.'.\Piwigo\Config\Config::userFields()['id'].' = c.author_id
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui
      ON ui.user_id = c.author_id
  WHERE '.implode(' AND ', $where_clauses).'
  ORDER BY c.date DESC
  LIMIT '.($per_page * $page_num).', '.$per_page.'
;';
    $list = [];
    foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)->fetchAllAssociative() as $row) {

        $medium_derivative = DerivativeImage::get_one(
            IMG_MEDIUM,
            [
            'id' => $row['image_id'],
            'path' => $row['path'],
            'representative_ext' => $row['representative_ext'],
      ]
        );
        $medium = $medium_derivative !== null ? $medium_derivative->get_url() : null;

        if (empty($row['author_id']) or $row['author_id'] == \Piwigo\Config\Config::guestId()) {
            $author_name = $row['author'];
        } else {
            $author_name = stripslashes((string) ($row['username'] ?? $row['author'] ?? l10n('guest')));
        }

        $list[] = [
          'id' => $row['id'],
          'admin_link' => get_root_url().'admin.php?page=photo-'.$row['image_id'],
          'medium_url' => $medium,
          'file' => $row['file'],
          'image_date_available' => format_date((string) $row['date_available'], ['day_name','day','month','year','time']),
          'author' => trigger_change('render_comment_author', $author_name),
          'author_status' => \Piwigo\Config\Config::webmasterId() == $row['author_id'] ? 'main_user' : $row['status'],
          'date' => format_date((string) $row['date'], ['day_name','day','month','year','time']),
          'content' => trigger_change('render_comment_content', $row['content']),
          'raw_content' => $row['content'],
          'is_pending' => ('false' == $row['validated']),
        ];
    }

    // filters
    $query = '
SELECT
  MIN(date) AS started_at,
  MAX(date) AS ended_at
FROM '.COMMENTS_TABLE.'
WHERE '.implode(' AND ', $where_clauses).'
;';

    $dates = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)->fetchAssociative() ?: [];

    unset($where_clauses['author_id']);
    $query = '
SELECT
  author,
  author_id,
  count(*) as nb_authors
FROM '.COMMENTS_TABLE.'
WHERE '.implode(' AND ', $where_clauses).'
GROUP BY author_id
;';

    $nb_authors_in = query2array($query);

    $total_count = is_numeric($total_comments) ? (int) $total_comments : 0;

    return [
      'summary' => $summary,
      'comments' => $list,
      'filters' => [
        'nb_authors' => $nb_authors_in,
        'started_at' => $dates['started_at'] ?? null,
        'ended_at' => $dates['ended_at'] ?? null,
      ],
      'paging' => [
        'page' => $params['page'],
        'per_page' => $params['per_page'],
        'total_pages' => max(0, ceil($total_count / max(1, $per_page)) - 1),
      ],
    ];
}

/**
 * API method
 * Delete comments
 * @since 16
 * @param mixed[] $params
 *
 */
/** @param array<mixed> $params */
function ws_userComments_delete(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|string
{
    include_once(PHPWG_ROOT_PATH.'include/functions_comment.inc.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    $raw_ids = is_array($params['comment_id']) ? $params['comment_id'] : [];
    $str_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw_ids);
    $comment_ids = array_map(fn (string $v): int => (int) $v, array_unique($str_ids));
    delete_user_comment($comment_ids);
    return 'Comment successfully deleted';
}

/**
 * API method
 * Validate comments
 * @since 16
 * @param mixed[] $params
 *
 */
/** @param array<mixed> $params */
function ws_userComments_validate(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|string
{
    include_once(PHPWG_ROOT_PATH.'include/functions_comment.inc.php');

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, l10n('Invalid security token'));
    }

    $raw_ids = is_array($params['comment_id']) ? $params['comment_id'] : [];
    $str_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw_ids);
    $comment_ids = array_map(fn (string $v): int => (int) $v, array_unique($str_ids));
    validate_user_comment($comment_ids);
    return 'Comment successfully validated';
}
