<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// $filter['enabled']: Filter is enabled
// $filter['recent_period']: Recent period used to computed filter data
// $filter['categories']: Computed data of filtered categories
// $filter['visible_categories']:
//  List of visible categories (count(visible) < count(forbidden) more often)
// $filter['visible_images']: List of visible images

// Bootstrap global, set by include/common.inc.php.
/** @var array<string, mixed> $user */
global $user;

/**
 * @var array<string, mixed> $filter
 * @var array<string, mixed> $header_notes
 */
global $filter, $header_notes;

if (! get_filter_page_value('cancel')) {
    if (isset($_GET['filter'])) {
        $filter['matches'] = [];
        $filter_get_param = $_GET['filter'];
        $filter['enabled'] =
          is_string($filter_get_param)
          && preg_match('/^start-recent-(\d+)$/', $filter_get_param, $filter['matches']) === 1;
    } else {
        $filter['enabled'] = pwg_get_session_var('filter_enabled', false);
    }
} else {
    $filter['enabled'] = false;
}

if ($filter['enabled']) {
    $filter_key = pwg_get_session_var('filter_check_key', [
        'user' => 0,
        'recent_period' => -1,
        'time' => 0,
        'date' => '',
    ]);
    if (
        ! is_array($filter_key)
        || ! isset($filter_key['user'], $filter_key['recent_period'], $filter_key['time'], $filter_key['date'])
    ) {
        // Session data is only ever written below by this same file, but
        // guard against a missing/corrupted session value defensively.
        $filter_key = [
            'user' => 0,
            'recent_period' => -1,
            'time' => 0,
            'date' => '',
        ];
    }

    // $filter['matches'] was populated by preg_match()'s by-reference
    // $matches parameter above -- that call re-widens PHPStan's prior
    // narrowing of the array's value type, so re-narrow here.
    $filter_matches = is_array($filter['matches'] ?? null) ? $filter['matches'] : null;
    if ($filter_matches !== null) {
        // matches[1] always exists here: this branch is only reached when
        // $filter['enabled'] was set true via a successful preg_match()
        // above (see the single-capture-group regex), which always
        // populates both matches[0] and matches[1].
        $filter['recent_period'] = $filter_matches[1] ?? null;
    } else {
        $filter['recent_period'] = $filter_key['recent_period'] > 0 ? $filter_key['recent_period'] : $user['recent_period'];
    }

    // $filter['recent_period'] above comes from an untyped regex capture,
    // the cached session value, or $user['recent_period'] -- all of unknown
    // origin -- narrow once to a definite int for every numeric use below.
    $filter_recent_period = is_numeric($filter['recent_period']) ? (int) $filter['recent_period'] : 0;

    if (
        // New filter
        ! pwg_get_session_var('filter_enabled', false) or
        // Cache data updated
        $filter_key['time'] <= $user['cache_update_time'] or
        // Date, period, user are changed
        $filter_key['user'] != $user['id'] or
        $filter_key['recent_period'] != $filter['recent_period'] or
        $filter_key['date'] != date('Ymd')
    ) {
        // Need to compute dats
        $filter_key = [
            'user' => is_numeric($user['id']) ? (int) $user['id'] : 0,
            'recent_period' => $filter_recent_period,
            'time' => time(),
            'date' => date('Ymd'),
        ];

        $filter['categories'] = get_computed_categories($user, $filter_recent_period);

        $filter['visible_categories'] = implode(',', array_keys($filter['categories']));
        if (empty($filter['visible_categories'])) {
            // Must be not empty
            $filter['visible_categories'] = -1;
        }

        $query = '
SELECT
  distinct image_id
FROM ' .
  IMAGE_CATEGORY_TABLE . ' INNER JOIN ' . IMAGES_TABLE . ' ON image_id = id
WHERE ';
        // $filter['visible_categories'] is always non-empty here: either a
        // non-empty string (from the implode() above) or the literal -1
        // fallback set right above when that implode() was empty.
        $query .= '
  category_id  IN (' . $filter['visible_categories'] . ') and';
        $query .= '
    date_available >= ' . pwg_db_get_recent_period_expression($filter_recent_period);

        $visible_image_ids = array_from_query($query, 'image_id');
        $filter['visible_images'] = implode(',', array_filter($visible_image_ids, 'is_string'));

        if (empty($filter['visible_images'])) {
            // Must be not empty
            $filter['visible_images'] = -1;
        }

        // Save filter data on session
        pwg_set_session_var('filter_enabled', $filter['enabled']);
        pwg_set_session_var('filter_check_key', $filter_key);
        pwg_set_session_var('filter_categories', serialize($filter['categories']));
        pwg_set_session_var('filter_visible_categories', $filter['visible_categories']);
        pwg_set_session_var('filter_visible_images', $filter['visible_images']);
    } else {
        // Read only data
        $serialized_categories = pwg_get_session_var('filter_categories', serialize([]));
        $filter['categories'] = is_string($serialized_categories) ? unserialize($serialized_categories) : [];
        $filter['visible_categories'] = pwg_get_session_var('filter_visible_categories', '');
        $filter['visible_images'] = pwg_get_session_var('filter_visible_images', '');
    }
    unset($filter_key);
    if (get_filter_page_value('add_notes')) {
        $header_notes[] = l10n_dec(
            'Photos posted within the last %d day.',
            'Photos posted within the last %d days.',
            $filter_recent_period
        );
    }
    include_once PHPWG_ROOT_PATH . 'include/functions_filter.inc.php';
} else {
    if (pwg_get_session_var('filter_enabled', false)) {
        pwg_unset_session_var('filter_enabled');
        pwg_unset_session_var('filter_check_key');
        pwg_unset_session_var('filter_categories');
        pwg_unset_session_var('filter_visible_categories');
        pwg_unset_session_var('filter_visible_images');
    }
}
