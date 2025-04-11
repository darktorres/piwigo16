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

use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_session;

if (! functions::get_filter_page_value('cancel')) {
    if (isset($_GET['filter'])) {
        $filter['matches'] = [];
        $filter['enabled'] = preg_match('/^start-recent-(\d+)$/', $_GET['filter'], $filter['matches']) === 1;
    } else {
        $filter['enabled'] = functions_session::pwg_get_session_var('filter_enabled', false);
    }
} else {
    $filter['enabled'] = false;
}

if ($filter['enabled']) {
    $filter_key = functions_session::pwg_get_session_var('filter_check_key', [
        'user' => 0,
        'recent_period' => -1,
        'time' => 0,
        'date' => '',
    ]);
    if (isset($filter['matches'])) {
        $filter['recent_period'] = $filter['matches'][1];
    } else {
        $filter['recent_period'] = $filter_key['recent_period'] > 0 ? $filter_key['recent_period'] : $user['recent_period'];
    }

    if (! functions_session::pwg_get_session_var('filter_enabled', false) ||
        $filter_key['time'] <= $user['cache_update_time'] ||
        $filter_key['user'] != $user['id'] ||
        $filter_key['recent_period'] != $filter['recent_period'] ||
        $filter_key['date'] != date('Ymd')
    ) {
        // Need to compute dats
        $filter_key = [
            'user' => (int) $user['id'],
            'recent_period' => (int) $filter['recent_period'],
            'time' => time(),
            'date' => date('Ymd'),
        ];

        $filter['categories'] = functions_category::get_computed_categories($user, (int) $filter['recent_period']);

        $filter['visible_categories'] = implode(', ', array_keys($filter['categories']));

        if (empty($filter['visible_categories'])) {
            // Must be not empty
            $filter['visible_categories'] = -1;
        }

        $query = <<<SQL
            SELECT DISTINCT image_id
            FROM image_category
            INNER JOIN images ON image_id = id
            WHERE

            SQL;

        if (! empty($filter['visible_categories'])) {
            $query .= <<<SQL
                category_id IN ({$filter['visible_categories']}) AND

                SQL;
        }

        $recent_period_expression = $conf->sql_backend::pwg_db_get_recent_period_expression($filter['recent_period']);
        $query .= <<<SQL
            date_available >= {$recent_period_expression};
            SQL;

        $filter['visible_images'] = implode(',', $conf->sql_backend::query2array($query, null, 'image_id'));

        if (empty($filter['visible_images'])) {
            // Must be not empty
            $filter['visible_images'] = -1;
        }

        // Save filter data on session
        functions_session::pwg_set_session_var('filter_enabled', $filter['enabled']);
        functions_session::pwg_set_session_var('filter_check_key', $filter_key);
        functions_session::pwg_set_session_var('filter_categories', serialize($filter['categories']));
        functions_session::pwg_set_session_var('filter_visible_categories', $filter['visible_categories']);
        functions_session::pwg_set_session_var('filter_visible_images', $filter['visible_images']);
    } else {
        // Read only data
        $filter['categories'] = unserialize(functions_session::pwg_get_session_var('filter_categories', serialize([])));
        $filter['visible_categories'] = functions_session::pwg_get_session_var('filter_visible_categories', '');
        $filter['visible_images'] = functions_session::pwg_get_session_var('filter_visible_images', '');
    }

    unset($filter_key);
    if (functions::get_filter_page_value('add_notes')) {
        $header_notes[] = functions::l10n_dec(
            'Photos posted within the last %d day.',
            'Photos posted within the last %d days.',
            $filter['recent_period']
        );
    }
} elseif (functions_session::pwg_get_session_var('filter_enabled', false)) {
    functions_session::pwg_unset_session_var('filter_enabled');
    functions_session::pwg_unset_session_var('filter_check_key');
    functions_session::pwg_unset_session_var('filter_categories');
    functions_session::pwg_unset_session_var('filter_visible_categories');
    functions_session::pwg_unset_session_var('filter_visible_images');
}
