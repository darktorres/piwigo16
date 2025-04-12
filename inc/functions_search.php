<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Piwigo\inc\dblayer\functions_mysqli;
use SmartyException;

final class functions_search
{
    public const int QST_QUOTED = 0x01;

    public const int QST_NOT = 0x02;

    public const int QST_OR = 0x04;

    public const int QST_WILDCARD_BEGIN = 0x08;

    public const int QST_WILDCARD_END = 0x10;

    public const int QST_WILDCARD = self::QST_WILDCARD_BEGIN | self::QST_WILDCARD_END;

    public const int QST_BREAK = 0x20;

    public static function get_search_id_pattern(
        string $candidate
    ): ?string {
        $clause_pattern = null;

        if (preg_match('/^psk-\d{8}-[a-z0-9]{10}$/i', $candidate)) {
            $clause_pattern = "search_uuid = '%s'";
        } elseif (preg_match('/^\d+$/', $candidate)) {
            $clause_pattern = 'id = %u';
        }

        return $clause_pattern;
    }

    public static function get_search_info(
        string $candidate
    ): array|null {
        global $page;

        // $candidate might be a search.id or a search_uuid
        $clause_pattern = self::get_search_id_pattern($candidate);

        if (empty($clause_pattern)) {
            exit('Invalid search identifier');
        }

        $clause = sprintf($clause_pattern, $candidate);
        $query = <<<SQL
            SELECT *
            FROM search
            WHERE {$clause};
            SQL;
        $searches = functions_mysqli::query2array($query);

        if ($searches !== []) {
            // we don't want spies to be able to see the search rules of any prior search (performed
            // by any user). We don't want them to be try index.php?/search/123 then index.php?/search/124
            // and so on. That's why we have implemented search_uuid with random characters.
            //
            // We also don't want to break old search urls with only the numeric id, so we only break if
            // there is no uuid.
            //
            // We also don't want to die if we're in the API.
            if (functions::script_basename() !== 'ws' &&
                $clause_pattern === 'id = %u' &&
                isset($searches[0]['search_uuid'])
            ) {
                functions_html::fatal_error('this search is not reachable with its id, need the search_uuid instead');
            }

            if (isset($page['section']) &&
                $page['section'] == 'search'
            ) {
                // to be used later in pwg_log
                $page['search_id'] = $searches[0]['id'];
            }

            return $searches[0];
        }

        return null;
    }

    /**
     * Returns search rules stored into a serialized array in "search"
     * table. Each search rules set is numerically identified.
     *
     * @throws SmartyException
     */
    public static function get_search_array(
        int|string $search_id
    ): array {
        global $user;

        $search = self::get_search_info($search_id);

        if (empty($search)) {
            functions_html::bad_request('this search identifier does not exist');
        }

        return unserialize($search['rules']);
    }

    /**
     * Returns the SQL clause for a search.
     * Transforms the array returned by get_search_array() into SQL sub-query.
     */
    public static function get_sql_search_clause(
        array $search
    ): array {
        // SQL where clauses are stored in $clauses array during query
        // construction
        $clauses = [];

        foreach (['file', 'name', 'comment', 'author'] as $textfield) {
            if (isset($search['fields'][$textfield])) {
                $local_clauses = [];

                foreach ($search['fields'][$textfield]['words'] as $word) {
                    $local_clauses[] = $textfield === 'author' ? $textfield . "='" . $word . "'" : $textfield . " LIKE '%" . $word . "%'";
                }

                if ($local_clauses !== []) {
                    // adds brackets around where clauses
                    $local_clauses = functions::prepend_append_array_items($local_clauses, '(', ')');

                    $clauses[] = implode(
                        ' ' . $search['fields'][$textfield]['mode'] . ' ',
                        $local_clauses
                    );
                }
            }
        }

        if (isset($search['fields']['allwords']) &&
            ! empty($search['fields']['allwords']['words']) &&
            count($search['fields']['allwords']['fields']) > 0
        ) {
            // 1) we search in regular fields (ie, the ones in the piwigo_images table)
            $fields = ['file', 'name', 'comment', 'author'];

            if (isset($search['fields']['allwords']['fields']) &&
                count($search['fields']['allwords']['fields']) > 0
            ) {
                $fields = array_intersect($fields, $search['fields']['allwords']['fields']);
            }

            $cat_fields_dictionary = [
                'cat-title' => 'name',
                'cat-desc' => 'comment',
            ];
            $cat_fields = array_intersect(array_keys($cat_fields_dictionary), $search['fields']['allwords']['fields']);

            // in the OR mode, request bust be :
            // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
            // OR (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
            //
            // in the AND mode :
            // ((field1 LIKE '%word1%' OR field2 LIKE '%word1%')
            // AND (field1 LIKE '%word2%' OR field2 LIKE '%word2%'))
            $word_clauses = [];
            $cat_ids_by_word = [];
            $tag_ids_by_word = [];

            foreach ($search['fields']['allwords']['words'] as $word) {
                $field_clauses = [];

                foreach ($fields as $field) {
                    $field_clauses[] = $field . " LIKE '%" . $word . "%'";
                }

                if ($cat_fields !== []) {
                    $cat_word_clauses = [];
                    $cat_field_clauses = [];

                    foreach ($cat_fields as $cat_field) {
                        $cat_field_clauses[] = $cat_fields_dictionary[$cat_field] . " LIKE '%" . $word . "%'";
                    }

                    // adds brackets around where clauses
                    $cat_word_clauses[] = implode(' OR ', $cat_field_clauses);

                    $catWordClauses = implode(' OR ', $cat_word_clauses);
                    $query = <<<SQL
                        SELECT id
                        FROM categories
                        WHERE {$catWordClauses};
                        SQL;
                    $cat_ids = functions_mysqli::query2array($query, null, 'id');
                    $cat_ids_by_word[$word] = $cat_ids;

                    if ($cat_ids !== []) {
                        $catIdsList = implode(', ', $cat_ids);
                        $query = <<<SQL
                            SELECT image_id
                            FROM image_category
                            WHERE category_id IN ({$catIdsList});
                            SQL;
                        $cat_image_ids = functions_mysqli::query2array($query, null, 'image_id');

                        if ($cat_image_ids !== []) {
                            $field_clauses[] = 'id IN (' . implode(', ', $cat_image_ids) . ')';
                        }
                    }
                }

                // search_in_tags
                if (in_array('tags', $search['fields']['allwords']['fields'])) {
                    $query = <<<SQL
                        SELECT id
                        FROM tags
                        WHERE name LIKE '%{$word}%';
                        SQL;
                    $tag_ids = functions_mysqli::query2array($query, null, 'id');
                    $tag_ids_by_word[$word] = $tag_ids;

                    if ($tag_ids !== []) {
                        $tagIdsList = implode(', ', $tag_ids);
                        $query = <<<SQL
                            SELECT image_id
                            FROM image_tag
                            WHERE tag_id IN ({$tagIdsList});
                            SQL;
                        $tag_image_ids = functions_mysqli::query2array($query, null, 'image_id');

                        if ($tag_image_ids !== []) {
                            $field_clauses[] = 'id IN (' . implode(', ', $tag_image_ids) . ')';
                        }
                    }
                }

                if ($field_clauses !== []) {
                    // adds brackets around where clauses
                    $word_clauses[] = implode(
                        "\n          OR ",
                        $field_clauses
                    );
                }
            }

            if ($word_clauses !== []) {
                array_walk(
                    $word_clauses,
                    function (string &$s): void { $s = '(' . $s . ')'; }
                );
            }

            // make sure the "mode" is either OR or AND
            if (! in_array($search['fields']['allwords']['mode'], ['OR', 'AND'])) {
                $search['fields']['allwords']['mode'] = 'AND';
            }

            $clauses[] = "\n         " . implode(
                "\n         " . $search['fields']['allwords']['mode'] . "\n         ",
                $word_clauses
            );

            if ($cat_ids_by_word !== []) {
                $matching_cat_ids = null;

                foreach ($cat_ids_by_word as $idx => $cat_ids) {
                    if ($matching_cat_ids === null) {
                        // first iteration
                        $matching_cat_ids = $cat_ids;
                    } else {
                        $matching_cat_ids = array_merge($matching_cat_ids, $cat_ids);
                    }
                }

                $matching_cat_ids = array_unique($matching_cat_ids);
            }

            if ($tag_ids_by_word !== []) {
                $matching_tag_ids = null;

                foreach ($tag_ids_by_word as $idx => $tag_ids) {
                    if ($matching_tag_ids === null) {
                        // first iteration
                        $matching_tag_ids = $tag_ids;
                    } else {
                        $matching_tag_ids = array_merge($matching_tag_ids, $tag_ids);
                    }
                }

                $matching_tag_ids = array_unique($matching_tag_ids);
            }
        }

        foreach (['date_available', 'date_creation'] as $datefield) {
            if (isset($search['fields'][$datefield])) {
                $clauses[] = $datefield . " = '" . $search['fields'][$datefield]['date'] . "'";
            }

            foreach (['after', 'before'] as $suffix) {
                $key = $datefield . '-' . $suffix;

                if (isset($search['fields'][$key])) {
                    $clauses[] = $datefield .
                      ($suffix === 'after' ? ' >' : ' <') .
                      ($search['fields'][$key]['inc'] ? '=' : '') .
                      " '" . $search['fields'][$key]['date'] . "'";
                }
            }
        }

        if (! empty($search['fields']['date_posted'])) {
            $options = [
                '24h' => '24 HOUR',
                '7d' => '7 DAY',
                '30d' => '30 DAY',
                '3m' => '3 MONTH',
                '6m' => '6 MONTH',
                '1y' => '1 YEAR',
            ];

            if (isset($options[$search['fields']['date_posted']])) {
                $clauses[] = 'date_available > SUBDATE(NOW(), INTERVAL ' . $options[$search['fields']['date_posted']] . ')';
            } elseif (preg_match('/^y(\d+)$/', $search['fields']['date_posted'], $matches)) {
                // that is for y2023 = all photos posted in 2022
                $clauses[] = 'YEAR(date_available) = ' . $matches[1];
            }
        }

        if (! empty($search['fields']['filetypes'])) {
            $filetypes_clauses = [];

            foreach ($search['fields']['filetypes'] as $ext) {
                $filetypes_clauses[] = "path LIKE '%." . $ext . "'";
            }

            $clauses[] = implode(' OR ', $filetypes_clauses);
        }

        if (! empty($search['fields']['added_by'])) {
            $clauses[] = 'added_by IN (' . implode(', ', $search['fields']['added_by']) . ')';
        }

        if (isset($search['fields']['cat']) &&
            ! empty($search['fields']['cat']['words'])
        ) {
            if ($search['fields']['cat']['sub_inc']) {
                // searching all the categories id of sub-categories
                $cat_ids = functions_category::get_subcat_ids($search['fields']['cat']['words']);
            } else {
                $cat_ids = $search['fields']['cat']['words'];
            }

            $local_clause = 'category_id IN (' . implode(', ', $cat_ids) . ')';
            $clauses[] = $local_clause;
        }

        // adds brackets around where clauses
        $clauses = functions::prepend_append_array_items($clauses, '(', ')');

        $where_separator =
          implode(
              "\n    " . $search['mode'] . ' ',
              $clauses
          );

        $search_clause = $where_separator;

        return [
            $search_clause,
            isset($matching_cat_ids) ? array_values($matching_cat_ids) : null,
            isset($matching_tag_ids) ? array_values($matching_tag_ids) : null,
        ];
    }

    /**
     * Returns the list of items corresponding to the advanced search array.
     *
     * @param string $images_where optional additional restriction on images table
     */
    public static function get_regular_search_results(
        array $search,
        string $images_where = ''
    ): array {
        global $conf, $logger;

        $logger->debug(__FUNCTION__, $search);

        $has_filters_filled = false;

        $forbidden = functions_user::get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ],
            "\n  AND"
        );

        $items = [];
        $tag_items = [];

        if (isset($search['fields']['tags'])) {
            if (! empty($search['fields']['tags']['words'])) {
                $has_filters_filled = true;
            }

            $tag_items = functions_tag::get_image_ids_for_tags(
                $search['fields']['tags']['words'],
                $search['fields']['tags']['mode']
            );

            $logger->debug(__FUNCTION__ . ' ' . count($tag_items) . ' items in $tag_items');
        }

        [$search_clause, $matching_cat_ids, $matching_tag_ids] = self::get_sql_search_clause($search);

        if (! empty($search_clause)) {
            $has_filters_filled = true;

            $query = <<<SQL
                SELECT DISTINCT id
                FROM images i
                INNER JOIN image_category AS ic ON id = ic.image_id
                LEFT JOIN image_tag AS it ON id = it.image_id
                WHERE {$search_clause}

                SQL;

            if (! empty($images_where)) {
                $query .= <<<SQL
                    AND {$images_where}

                    SQL;
            }

            $query .= <<<SQL
                {$forbidden}
                {$conf->order_by};
                SQL;
            $items = functions_mysqli::query2array($query, null, 'id');

            $logger->debug(__FUNCTION__ . ' ' . count($items) . ' items in $items');
        }

        if ($tag_items !== []) {
            switch ($search['mode']) {
                case 'AND':
                    if (empty($search_clause) &&
                        ! isset($search_in_tags_items)
                    ) {
                        $items = $tag_items;
                    } else {
                        $items = array_values(array_intersect($items, $tag_items));
                    }

                    break;

                case 'OR':
                    $items = array_values(
                        array_unique(
                            array_merge(
                                $items,
                                $tag_items
                            )
                        )
                    );
                    break;
            }
        }

        return [
            'items' => $items,
            'search_details' => [
                'matching_cat_ids' => $matching_cat_ids,
                'matching_tag_ids' => $matching_tag_ids,
                'has_filters_filled' => $has_filters_filled,
            ],
        ];
    }

    public static function qsearch_get_text_token_search_sql(
        QSingleToken $token,
        array $fields
    ): array {
        global $page;

        $clauses = [];
        $variants = array_merge([$token->term], $token->variants);
        $fts = [];

        foreach ($variants as $variant) {
            $use_ft = mb_strlen($variant) > 3;

            if (($token->modifier & self::QST_WILDCARD_BEGIN) !== 0) {
                $use_ft = false;
            }

            if (($token->modifier & (self::QST_QUOTED | self::QST_WILDCARD_END) === (self::QST_QUOTED | self::QST_WILDCARD_END)) !== 0) {
                $use_ft = false;
            }

            if ($use_ft) {
                $max = max(array_map(
                    mb_strlen(...),
                    preg_split('/[' . preg_quote('-\'!"#$%&()*+,./:;<=>?@[\]^`{|}~', '/') . ']+/', $variant)
                ));

                if ($max < 4) {
                    $use_ft = false;
                }
            }

            if (! $use_ft) { // odd term or too short for full text search; fallback to regex but unfortunately this is diacritic/accent sensitive
                if (! isset($page['use_regexp_ICU'])) {
                    // Prior to MySQL 8.0.4, MySQL used the Henry Spencer regular expression library to support
                    // regular expression operations, rather than International Components for Unicode (ICU)
                    $page['use_regexp_ICU'] = false;
                    $db_version = functions_mysqli::pwg_get_db_version();

                    if (! preg_match('/mariadb/i', $db_version) &&
                        version_compare($db_version, '8.0.4', '>')
                    ) {
                        $page['use_regexp_ICU'] = true;
                    }
                }

                $pre = (($token->modifier & self::QST_WILDCARD_BEGIN) !== 0) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:<:]]');
                $post = (($token->modifier & self::QST_WILDCARD_END) !== 0) ? '' : ($page['use_regexp_ICU'] ? '\\\\b' : '[[:>:]]');

                foreach ($fields as $field) {
                    $clauses[] = $field . " REGEXP '" . $pre . addslashes(preg_quote($variant)) . $post . "'";
                }
            } else {
                $ft = $variant;

                if (($token->modifier & self::QST_QUOTED) !== 0) {
                    $ft = '"' . $ft . '"';
                }

                if (($token->modifier & self::QST_WILDCARD_END) !== 0) {
                    $ft .= '*';
                }

                $fts[] = $ft;
            }
        }

        if ($fts !== []) {
            $clauses[] = 'MATCH(' . implode(', ', $fields) . ") AGAINST( '" . addslashes(implode(' ', $fts)) . "' IN BOOLEAN MODE)";
        }

        return $clauses;
    }

    public static function qsearch_get_images(
        QExpression $expr,
        QResults $qsr
    ): void {
        $qsr->images_iids = array_fill(0, count($expr->stokens), []);

        $query_base = <<<SQL
            SELECT id
            FROM images i
            WHERE

            SQL;
        $counter = count($expr->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $token = $expr->stokens[$i];
            $scope_id = isset($token->scope) ? $token->scope->id : 'photo';
            $clauses = [];

            $like = addslashes($token->term);
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $like); // escape LIKE specials %_
            $file_like = "CONVERT(file, CHAR) LIKE '%" . $like . "%'";

            switch ($scope_id) {
                case 'photo':
                    $clauses[] = $file_like;
                    $clauses = array_merge($clauses, self::qsearch_get_text_token_search_sql($token, ['name', 'comment']));
                    break;

                case 'file':
                    $clauses[] = $file_like;
                    break;

                case 'author':
                    if (strlen($token->term) !== 0) {
                        $clauses = array_merge($clauses, self::qsearch_get_text_token_search_sql($token, ['author']));
                    } elseif (($token->modifier & self::QST_WILDCARD) !== 0) {
                        $clauses[] = 'author IS NOT NULL';
                    } else {
                        $clauses[] = 'author IS NULL';
                    }

                    break;

                case 'width':
                case 'height':
                    $clauses[] = $token->scope->get_sql($scope_id, $token);
                    break;

                case 'ratio':
                    $clauses[] = $token->scope->get_sql('width/height', $token);
                    break;

                case 'size':
                    $clauses[] = $token->scope->get_sql('width*height', $token);
                    break;

                case 'hits':
                    $clauses[] = $token->scope->get_sql('hit', $token);
                    break;

                case 'score':
                    $clauses[] = $token->scope->get_sql('rating_score', $token);
                    break;

                case 'filesize':
                    $clauses[] = $token->scope->get_sql('1024*filesize', $token);
                    break;

                case 'created':
                    $clauses[] = $token->scope->get_sql('date_creation', $token);
                    break;

                case 'posted':
                    $clauses[] = $token->scope->get_sql('date_available', $token);
                    break;

                case 'id':
                    $clauses[] = $token->scope->get_sql($scope_id, $token);
                    break;

                default:
                    // allow plugins to have their own scope with columns added in db by themselves
                    $clauses = functions_plugins::trigger_change('qsearch_get_images_sql_scopes', $clauses, $token, $expr);
                    break;
            }

            if (! empty($clauses)) {
                $query = $query_base . '(' . implode("\n OR ", $clauses) . ')';
                $qsr->images_iids[$i] = functions_mysqli::query2array($query, null, 'id');
            }
        }
    }

    public static function qsearch_get_tags(
        QExpression $expr,
        QResults $qsr
    ): void {
        $token_tag_ids = array_fill(0, count($expr->stokens), []);
        $qsr->tag_iids = $token_tag_ids;
        $all_tags = [];
        $counter = count($expr->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $token = $expr->stokens[$i];

            if (isset($token->scope) &&
                $token->scope->id != 'tag'
            ) {
                continue;
            }

            if (empty($token->term)) {
                continue;
            }

            $clauses = self::qsearch_get_text_token_search_sql($token, ['name']);
            $clausesList = implode("\n OR ", $clauses);
            $query = <<<SQL
                SELECT * FROM tags
                WHERE ({$clausesList});
                SQL;
            $result = functions_mysqli::pwg_query($query);

            while ($tag = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $token_tag_ids[$i][] = $tag['id'];
                $all_tags[$tag['id']] = $tag;
            }
        }

        // check adjacent short words
        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3) &&
               (($expr->stoken_modifiers[$i] & (self::QST_QUOTED | self::QST_WILDCARD)) == 0) &&
                (($expr->stoken_modifiers[$i + 1] & (self::QST_BREAK | self::QST_QUOTED | self::QST_WILDCARD)) == 0)
            ) {
                $common = array_intersect($token_tag_ids[$i], $token_tag_ids[$i + 1]);

                if ($common !== []) {
                    $token_tag_ids[$i] = $token_tag_ids[$i + 1] = $common;
                }
            }
        }

        // get images
        $positive_ids = [];
        $not_ids = [];
        $counter = count($expr->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $tag_ids = $token_tag_ids[$i];
            $token = $expr->stokens[$i];

            if (! empty($tag_ids)) {
                $tagIdsList = implode(', ', $tag_ids);
                $query = <<<SQL
                    SELECT image_id FROM image_tag
                    WHERE tag_id IN ({$tagIdsList})
                    GROUP BY image_id;
                    SQL;
                $qsr->tag_iids[$i] = functions_mysqli::query2array($query, null, 'image_id');

                if (($expr->stoken_modifiers[$i] & self::QST_NOT) !== 0) {
                    $not_ids = array_merge($not_ids, $tag_ids);
                } elseif (strlen($token->term) > 2 ||
                    count($expr->stokens) == 1 ||
                    isset($token->scope) ||
                   ($token->modifier & (self::QST_WILDCARD | self::QST_QUOTED))) {
                    // add tag ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $tag_ids);
                }
            } elseif (isset($token->scope) && $token->scope->id == 'tag' && strlen($token->term) == 0) {
                if (($token->modifier & self::QST_WILDCARD) !== 0) { // eg. 'tag:*' returns all tagged images
                    $qsr->tag_iids[$i] = functions_mysqli::query2array('SELECT DISTINCT image_id FROM image_tag;', null, 'image_id');
                } else { // eg. 'tag:' returns all untagged images
                    $qsr->tag_iids[$i] = functions_mysqli::query2array('SELECT id FROM images LEFT JOIN image_tag ON id = image_id WHERE image_id IS NULL;', null, 'id');
                }
            }
        }

        $all_tags = array_intersect_key($all_tags, array_flip(array_diff($positive_ids, $not_ids)));
        usort($all_tags, functions_html::tag_alpha_compare(...));

        foreach ($all_tags as &$tag) {
            $tag['name'] = functions_plugins::trigger_change('render_tag_name', $tag['name'], $tag);
        }

        $qsr->all_tags = $all_tags;
        $qsr->tag_ids = $token_tag_ids;
    }

    public static function qsearch_get_categories(
        QExpression $expr,
        QResults $qsr
    ): void {
        global $user, $conf;
        $token_cat_ids = array_fill(0, count($expr->stokens), []);
        $qsr->cat_iids = $token_cat_ids;
        $all_cats = [];
        $counter = count($expr->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $token = $expr->stokens[$i];

            if (isset($token->scope) && $token->scope->id != 'category') { // not relevant yet
                continue;
            }

            if (empty($token->term)) {
                continue;
            }

            $clauses = self::qsearch_get_text_token_search_sql($token, ['name', 'comment']);
            $clausesList = implode("\n OR ", $clauses);
            $query = <<<SQL
                SELECT *
                FROM categories
                INNER JOIN user_cache_categories ON id = cat_id AND user_id = {$user['id']}
                WHERE ({$clausesList});
                SQL;
            $result = functions_mysqli::pwg_query($query);

            while ($cat = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $token_cat_ids[$i][] = $cat['id'];
                $all_cats[$cat['id']] = $cat;
            }
        }

        // check adjacent short words
        for ($i = 0; $i < count($expr->stokens) - 1; $i++) {
            if ((strlen($expr->stokens[$i]->term) <= 3 || strlen($expr->stokens[$i + 1]->term) <= 3) &&
               (($expr->stoken_modifiers[$i] & (self::QST_QUOTED | self::QST_WILDCARD)) == 0) &&
               (($expr->stoken_modifiers[$i + 1] & (self::QST_BREAK | self::QST_QUOTED | self::QST_WILDCARD)) == 0)
            ) {
                $common = array_intersect($token_cat_ids[$i], $token_cat_ids[$i + 1]);

                if ($common !== []) {
                    $token_cat_ids[$i] = $token_cat_ids[$i + 1] = $common;
                }
            }
        }

        // get images
        $positive_ids = [];
        $not_ids = [];
        $counter = count($expr->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $cat_ids = $token_cat_ids[$i];
            $token = $expr->stokens[$i];

            if (! empty($cat_ids)) {
                if ($conf->quick_search_include_sub_albums) {
                    $subcatIdsList = implode(', ', functions_category::get_subcat_ids($cat_ids));
                    $query = <<<SQL
                        SELECT id
                        FROM categories
                        INNER JOIN user_cache_categories ON id = cat_id AND user_id = {$user['id']}
                        WHERE id IN ({$subcatIdsList});
                        SQL;
                    $cat_ids = functions_mysqli::query2array($query, null, 'id');
                }

                $catIdsList = implode(', ', $cat_ids);
                $query = <<<SQL
                    SELECT image_id FROM image_category
                    WHERE category_id IN ({$catIdsList})
                    GROUP BY image_id;
                    SQL;
                $qsr->cat_iids[$i] = functions_mysqli::query2array($query, null, 'image_id');

                if (($expr->stoken_modifiers[$i] & self::QST_NOT) !== 0) {
                    $not_ids = array_merge($not_ids, $cat_ids);
                } elseif (strlen($token->term) > 2 ||
                    count($expr->stokens) == 1 ||
                    isset($token->scope) ||
                    ($token->modifier & (self::QST_WILDCARD | self::QST_QUOTED))) {
                    // add cat ids to list only if the word is not too short (such as de / la /les ...)
                    $positive_ids = array_merge($positive_ids, $cat_ids);
                }
            } elseif (isset($token->scope) &&
                      $token->scope->id == 'category' &&
                      strlen($token->term) == 0
            ) {
                if (($token->modifier & self::QST_WILDCARD) !== 0) { // eg. 'category:*' returns all images associated to an album
                    $qsr->cat_iids[$i] = functions_mysqli::query2array('SELECT DISTINCT image_id FROM image_category;', null, 'image_id');
                } else { // eg. 'category:' returns all orphan images
                    $qsr->cat_iids[$i] = functions_mysqli::query2array('SELECT id FROM images LEFT JOIN image_category ON id = image_id WHERE image_id IS NULL;', null, 'id');
                }
            }
        }

        $all_cats = array_intersect_key($all_cats, array_flip(array_diff($positive_ids, $not_ids)));
        usort($all_cats, functions_html::tag_alpha_compare(...));

        foreach ($all_cats as &$cat) {
            $cat['name'] = functions_plugins::trigger_change('render_category_name', $cat['name'], $cat);
        }

        $qsr->all_cats = $all_cats;
        $qsr->cat_ids = $token_cat_ids;
    }

    public static function qsearch_eval(
        QMultiToken $expr,
        QResults $qsr,
        bool &$qualifies,
        array &$ignored_terms
    ): array {
        $qualifies = false; // until we find at least one positive term
        $ignored_terms = [];
        $ids = [];
        $not_ids = [];
        $counter = count($expr->tokens);

        for ($i = 0; $i < $counter; $i++) {
            $crt = $expr->tokens[$i];

            if ($crt->is_single) {
                $crt_ids = $qsr->iids[$crt->idx] = array_unique(
                    array_merge(
                        $qsr->images_iids[$crt->idx],
                        $qsr->cat_iids[$crt->idx],
                        $qsr->tag_iids[$crt->idx]
                    )
                );
                $crt_qualifies = $crt_ids !== [] || count($qsr->tag_ids[$crt->idx]) > 0;
                $crt_ignored_terms = $crt_qualifies ? [] : [(string) $crt];
            } else {
                $crt_ids = self::qsearch_eval($crt, $qsr, $crt_qualifies, $crt_ignored_terms);
            }

            $modifier = $crt->modifier;

            if (($modifier & self::QST_NOT) !== 0) {
                $not_ids = array_unique(array_merge($not_ids, $crt_ids));
            } else {
                $ignored_terms = array_merge($ignored_terms, $crt_ignored_terms);

                if (($modifier & self::QST_OR) !== 0) {
                    $ids = array_unique(array_merge($ids, $crt_ids));
                    $qualifies |= $crt_qualifies;
                } elseif ($crt_qualifies) {
                    $ids = $qualifies ? array_intersect($ids, $crt_ids) : $crt_ids;
                    $qualifies = true;
                }
            }
        }

        if ($not_ids !== []) {
            $ids = array_diff($ids, $not_ids);
        }

        return $ids;
    }

    /**
     * Returns the search results corresponding to a quick/query search.
     * A quick/query search returns many items (search is not strict), but results
     * are sorted by relevance unless $super_order_by is true. Returns:
     *  array (
     *    'items' => array of matching images
     *    'qs'    => array(
     *      'unmatched_terms' => array of terms from the input string that were not matched
     *      'matching_tags' => array of matching tags
     *      'matching_cats' => array of matching categories
     *      'matching_cats_no_images' =>array(99) - matching categories without images
     *      )
     *    )
     *
     * @param array{
     *     permissions: bool,
     *     images_where: string,
     *     super_order_by: bool,
     * } $options
     */
    public static function get_quick_search_results(
        string $q,
        array $options
    ): array {
        global $persistent_cache, $conf, $user;

        $cache_key = $persistent_cache->make_key([
            strtolower($q),
            $conf->order_by,
            $user['id'], $user['cache_update_time'],
            isset($options['permissions']) ? (bool) $options['permissions'] : true,
            $options['images_where'] ?? '',
        ]);

        if ($persistent_cache->get($cache_key, $res)) {
            return $res;
        }

        $res = self::get_quick_search_results_no_cache($q, $options);

        if (count($res['items']) > 0) { // cache the results only if not empty - otherwise it is useless
            $persistent_cache->set($cache_key, $res, 300);
        }

        return $res;
    }

    /**
     * @see get_quick_search_results but without result caching
     */
    public static function get_quick_search_results_no_cache(
        string $q,
        array $options
    ): array {
        global $conf;

        $q = trim(stripslashes($q));
        $search_results =
          [
              'items' => [],
              'qs' => [
                  'q' => $q,
              ],
          ];

        $q = functions_plugins::trigger_change('qsearch_pre', $q);

        $scopes = [];
        $scopes[] = new QSearchScope('tag', ['tags']);
        $scopes[] = new QSearchScope('photo', ['photos']);
        $scopes[] = new QSearchScope('file', ['filename']);
        $scopes[] = new QSearchScope('author', [], true);
        $scopes[] = new QNumericRangeScope('width', []);
        $scopes[] = new QNumericRangeScope('height', []);
        $scopes[] = new QNumericRangeScope('ratio', [], false, 0.001);
        $scopes[] = new QNumericRangeScope('size', []);
        $scopes[] = new QNumericRangeScope('filesize', []);
        $scopes[] = new QNumericRangeScope('hits', ['hit', 'visit', 'visits']);
        $scopes[] = new QNumericRangeScope('score', ['rating'], true);
        $scopes[] = new QNumericRangeScope('id', []);

        $createdDateAliases = ['taken', 'shot'];
        $postedDateAliases = ['added'];

        if ($conf->calendar_datefield === 'date_creation') {
            $createdDateAliases[] = 'date';
        } else {
            $postedDateAliases[] = 'date';
        }

        $scopes[] = new QDateRangeScope('created', $createdDateAliases, true);
        $scopes[] = new QDateRangeScope('posted', $postedDateAliases);

        // allow plugins to add their own scopes
        $scopes = functions_plugins::trigger_change('qsearch_get_scopes', $scopes);
        $expression = new QExpression($q, $scopes);

        // get inflections for terms
        $inflector = null;
        $lang_code = substr(functions_user::get_default_language(), 0, 2);

        if (file_exists('./inc/inflectors/Inflector_' . $lang_code . '.php')) {
            require_once './inc/inflectors/Inflector_' . $lang_code . '.php';
        }

        $class_name = 'Inflector_' . $lang_code;

        if (class_exists($class_name)) {
            $inflector = new $class_name();

            foreach ($expression->stokens as $token) {
                if (isset($token->scope) &&
                    ! $token->scope->is_text
                ) {
                    continue;
                }

                if (strlen($token->term) > 2 &&
                   ($token->modifier & (self::QST_QUOTED | self::QST_WILDCARD)) == 0 &&
                    strcspn($token->term, "'0123456789") == strlen($token->term)
                ) {
                    $token->variants = array_unique(array_diff($inflector->get_variants($token->term), [$token->term]));
                }
            }
        }

        functions_plugins::trigger_notify('qsearch_expression_parsed', $expression);
        //var_export($expression);

        if (count($expression->stokens) == 0) {
            return $search_results;
        }

        $qsr = new QResults();
        self::qsearch_get_tags($expression, $qsr);
        self::qsearch_get_categories($expression, $qsr);
        self::qsearch_get_images($expression, $qsr);

        // allow plugins to evaluate their own scopes
        functions_plugins::trigger_notify('qsearch_before_eval', $expression, $qsr);

        $ids = self::qsearch_eval($expression, $qsr, $tmp, $search_results['qs']['unmatched_terms']);

        $debug[] = "<!--\nparsed: " . htmlspecialchars($expression);
        $debug[] = count($expression->stokens) . ' tokens';
        $counter = count($expression->stokens);

        for ($i = 0; $i < $counter; $i++) {
            $debug[] = htmlspecialchars($expression->stokens[$i]) . ': ' . count($qsr->tag_ids[$i]) . ' tags, ' . count($qsr->tag_iids[$i]) . ' tiids, ' . count($qsr->images_iids[$i]) . ' iiids, ' . count($qsr->iids[$i]) . ' iids'
              . ' modifier:' . dechex($expression->stoken_modifiers[$i])
              . (empty($expression->stokens[$i]->variants) ? '' : ' variants: ' . htmlspecialchars(implode(', ', $expression->stokens[$i]->variants)));
        }

        $debug[] = 'before perms ' . count($ids);

        $search_results['qs']['matching_tags'] = $qsr->all_tags;
        $search_results['qs']['matching_cats'] = $qsr->all_cats;
        $search_results = functions_plugins::trigger_change('qsearch_results', $search_results, $expression, $qsr);

        if (isset($search_results['items'])) {
            $ids = array_merge($ids, $search_results['items']);
        }

        global $template;

        if ($ids === []) {
            $debug[] = '-->';
            $template->append('footer_elements', implode("\n", $debug));
            return $search_results;
        }

        $permissions = $options['permissions'] ?? true;

        $where_clauses = [];
        $where_clauses[] = 'i.id IN (' . implode(', ', $ids) . ')';

        if (! empty($options['images_where'])) {
            $where_clauses[] = '(' . $options['images_where'] . ')';
        }

        if ($permissions) {
            $where_clauses[] = functions_user::get_sql_condition_FandF(
                [
                    'forbidden_categories' => 'category_id',
                    'forbidden_images' => 'i.id',
                ],
                null,
                true
            );
        }

        $whereClauses = implode("\n AND ", $where_clauses);
        $query = <<<SQL
            SELECT DISTINCT id FROM images i

            SQL;

        if ($permissions) {
            $query .= <<<SQL
                INNER JOIN image_category AS ic ON id = ic.image_id

                SQL;
        }

        $query .= <<<SQL
            WHERE {$whereClauses}
            {$conf->order_by};
            SQL;

        $ids = functions_mysqli::query2array($query, null, 'id');

        $debug[] = count($ids) . ' final photo count -->';
        $template->append('footer_elements', implode("\n", $debug));

        $search_results['items'] = $ids;
        return $search_results;
    }

    /**
     * Returns an array of 'items' corresponding to the search id.
     * It can be either a quick search or a regular search.
     *
     * @param string $images_where optional additional restriction on images table
     * @throws SmartyException
     */
    public static function get_search_results(
        int|string $search_id,
        ?bool $super_order_by,
        string $images_where = ''
    ): array {
        $search = self::get_search_array($search_id);

        if (! isset($search['q'])) {
            return self::get_regular_search_results($search, $images_where);
        }

        return self::get_quick_search_results($search['q'], [
            'super_order_by' => $super_order_by,
            'images_where' => $images_where,
        ]);

    }

    public static function split_allwords(
        string $raw_allwords
    ): array|false|null {
        $words = null;

        // we specify the list of characters to trim, to add the ".". We don't want to split words
        // on "." but on ". ", and we have to deal with trailing dots.
        $raw_allwords = trim($raw_allwords, " \n\r\t\v\x00.");

        if (! preg_match('/^\s*$/', $raw_allwords)) {
            $drop_char_match = [';', '&', '(', ')', '<', '>', '`', "'", '"', '|', ',', '@', '?', '%', '. ', '[', ']', '{', '}', ':', '\\', '/', '=', "'", '!', '*'];
            $drop_char_replace = [' ', ' ', ' ', ' ', ' ', ' ', '', '', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', '', ' ', ' ', ' ', ' ', ' '];

            // Split words
            $words = array_unique(
                preg_split(
                    '/\s+/',
                    str_replace(
                        $drop_char_match,
                        $drop_char_replace,
                        $raw_allwords
                    )
                )
            );
        }

        return $words;
    }

    public static function get_available_search_uuid(): string
    {
        $candidate = 'psk-' . date('Ymd') . '-' . functions_session::generate_key(10);

        $query = <<<SQL
            SELECT COUNT(*)
            FROM search
            WHERE search_uuid = '{$candidate}';
            SQL;
        [$counter] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($counter == 0) {
            return $candidate;
        }

        return self::get_available_search_uuid();
    }

    public static function save_search(
        array $rules,
        ?string $forked_from = null
    ): array {
        global $user;

        [$dbnow] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT NOW();'));
        $search_uuid = self::get_available_search_uuid();

        functions_mysqli::single_insert(
            'search',
            [
                'rules' => functions_mysqli::pwg_db_real_escape_string(serialize($rules)),
                'created_on' => $dbnow,
                'created_by' => $user['user_id'],
                'search_uuid' => $search_uuid,
                'forked_from' => $forked_from,
            ]
        );

        if (! functions_user::is_a_guest() &&
            ! functions_user::is_generic()
        ) {
            functions_user::userprefs_update_param('gallery_search_filters', array_keys($rules['fields'] ?? []));
        }

        $url = functions_url::make_index_url(
            [
                'section' => 'search',
                'search' => $search_uuid,
            ]
        );

        return [$search_uuid, $url];
    }
}
