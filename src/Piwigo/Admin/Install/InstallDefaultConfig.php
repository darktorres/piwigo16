<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

/**
 * The `config` table's own default row set, seeded once per install by
 * {@see InstallDataSeeder::seed()} -- replaces the former `install/
 * config.sql` (deleted). `value` holds a native PHP value for every row
 * (`false`, `'clear'`, `['author' => true, ...]`, a literal `null` for
 * `mobile_theme`), mirroring exactly what `config.sql`'s own hand-encoded
 * JSON-text literals decoded to -- `InstallDataSeeder::seed()` re-encodes
 * each non-null value via `json_encode()` before writing, the same job
 * {@see \Piwigo\Config\ConfigService}'s own private `encode()` does at
 * runtime, so a row written here reads back identically to one written by
 * a real `confUpdateParam()` call later.
 *
 * `'index_search_in_set_action'` was the one row seeded as a *string*
 * (`config.sql`'s own literal was the quoted JSON string `'"true"'`),
 * preserved verbatim here rather than "corrected". It is a real bool now:
 * the string form is truthy whichever way the setting is set, so the
 * feature could not be turned off. See `CurrentConfig::$indexSearchInSetAction`.
 */
final class InstallDefaultConfig
{
    /**
     * @return list<array{param: string, value: mixed, comment?: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'param' => 'activate_comments',
                'value' => false,
                'comment' => 'Global parameter for usage of comments system',
            ],
            [
                'param' => 'nb_comment_page',
                'value' => 10,
                'comment' => 'number of comments to display on each page',
            ],
            [
                'param' => 'log',
                'value' => true,
                'comment' => 'keep an history of visits on your website',
            ],
            [
                'param' => 'comments_validation',
                'value' => false,
                'comment' => 'administrators validate users comments before becoming visible',
            ],
            [
                'param' => 'comments_forall',
                'value' => false,
                'comment' => 'even guest not registered can post comments',
            ],
            [
                'param' => 'comments_order',
                'value' => 'ASC',
                'comment' => 'comments order on picture page and cie',
            ],
            [
                'param' => 'comments_author_mandatory',
                'value' => false,
                'comment' => 'Comment author is mandatory',
            ],
            [
                'param' => 'comments_email_mandatory',
                'value' => false,
                'comment' => 'Comment email is mandatory',
            ],
            [
                'param' => 'comments_enable_website',
                'value' => true,
                'comment' => 'Enable "website" field on add comment form',
            ],
            [
                'param' => 'user_can_delete_comment',
                'value' => false,
                'comment' => 'administrators can allow user delete their own comments',
            ],
            [
                'param' => 'user_can_edit_comment',
                'value' => false,
                'comment' => 'administrators can allow user edit their own comments',
            ],
            [
                'param' => 'email_admin_on_comment_edition',
                'value' => false,
                'comment' => 'Send an email to the administrators when a comment is modified',
            ],
            [
                'param' => 'email_admin_on_comment_deletion',
                'value' => false,
                'comment' => 'Send an email to the administrators when a comment is deleted',
            ],
            [
                'param' => 'gallery_locked',
                'value' => false,
                'comment' => 'Lock your gallery temporary for non admin users',
            ],
            [
                'param' => 'gallery_title',
                'value' => '',
                'comment' => 'Title at top of each page and for RSS feed',
            ],
            [
                'param' => 'rate',
                'value' => false,
                'comment' => 'Rating pictures feature is enabled',
            ],
            [
                'param' => 'rate_anonymous',
                'value' => true,
                'comment' => 'Rating pictures feature is also enabled for visitors',
            ],
            [
                'param' => 'page_banner',
                'value' => '',
                'comment' => 'html displayed on the top each page of your gallery',
            ],
            [
                'param' => 'history_admin',
                'value' => false,
                'comment' => 'keep a history of administrator visits on your website',
            ],
            [
                'param' => 'history_guest',
                'value' => true,
                'comment' => 'keep a history of guest visits on your website',
            ],
            [
                'param' => 'allow_user_registration',
                'value' => true,
                'comment' => 'allow visitors to register?',
            ],
            [
                'param' => 'allow_user_customization',
                'value' => true,
                'comment' => 'allow users to customize their gallery?',
            ],
            [
                'param' => 'nb_categories_page',
                'value' => 12,
                'comment' => 'Param for categories pagination',
            ],
            [
                'param' => 'nbm_send_html_mail',
                'value' => true,
                'comment' => 'Send mail on HTML format for notification by mail',
            ],
            [
                'param' => 'nbm_send_mail_as',
                'value' => '',
                'comment' => 'Send mail as param value for notification by mail',
            ],
            [
                'param' => 'nbm_send_detailed_content',
                'value' => true,
                'comment' => 'Send detailed content for notification by mail',
            ],
            [
                'param' => 'nbm_complementary_mail_content',
                'value' => '',
                'comment' => 'Complementary mail content for notification by mail',
            ],
            [
                'param' => 'nbm_send_recent_post_dates',
                'value' => true,
                'comment' => 'Send recent post by dates for notification by mail',
            ],
            [
                'param' => 'email_admin_on_new_user',
                'value' => 'none',
                'comment' => 'Send an email to theadministrators when a user registers',
            ],
            [
                'param' => 'email_admin_on_comment',
                'value' => false,
                'comment' => 'Send an email to the administrators when a valid comment is entered',
            ],
            [
                'param' => 'email_admin_on_comment_validation',
                'value' => true,
                'comment' => 'Send an email to the administrators when a comment requires validation',
            ],
            [
                'param' => 'obligatory_user_mail_address',
                'value' => false,
                'comment' => 'Mail address is obligatory for users',
            ],
            [
                'param' => 'menubar_filter_icon',
                'value' => false,
                'comment' => 'Display filter icon',
            ],
            [
                'param' => 'index_sort_order_input',
                'value' => true,
                'comment' => 'Display image order selection list',
            ],
            [
                'param' => 'index_flat_icon',
                'value' => false,
                'comment' => 'Display flat icon',
            ],
            [
                'param' => 'index_posted_date_icon',
                'value' => true,
                'comment' => 'Display calendar by posted date',
            ],
            [
                'param' => 'index_created_date_icon',
                'value' => true,
                'comment' => 'Display calendar by creation date icon',
            ],
            [
                'param' => 'index_slideshow_icon',
                'value' => true,
                'comment' => 'Display slideshow icon',
            ],
            [
                'param' => 'index_new_icon',
                'value' => true,
                'comment' => 'Display new icons next albums and pictures',
            ],
            [
                'param' => 'picture_metadata_icon',
                'value' => true,
                'comment' => 'Display metadata icon on picture page',
            ],
            [
                'param' => 'picture_slideshow_icon',
                'value' => true,
                'comment' => 'Display slideshow icon on picture page',
            ],
            [
                'param' => 'picture_favorite_icon',
                'value' => true,
                'comment' => 'Display favorite icon on picture page',
            ],
            [
                'param' => 'picture_download_icon',
                'value' => true,
                'comment' => 'Display download icon on picture page',
            ],
            [
                'param' => 'picture_navigation_icons',
                'value' => true,
                'comment' => 'Display navigation icons on picture page',
            ],
            [
                'param' => 'picture_navigation_thumb',
                'value' => true,
                'comment' => 'Display navigation thumbnails on picture page',
            ],
            [
                'param' => 'picture_menu',
                'value' => false,
                'comment' => 'Show menubar on picture page',
            ],
            [
                'param' => 'picture_informations',
                'value' => [
                    'author' => true,
                    'created_on' => true,
                    'posted_on' => true,
                    'dimensions' => false,
                    'file' => false,
                    'filesize' => false,
                    'tags' => true,
                    'categories' => true,
                    'visits' => true,
                    'rating_score' => true,
                    'privacy_level' => true,
                ],
                'comment' => 'Information displayed on picture page',
            ],
            [
                'param' => 'week_starts_on',
                'value' => 'monday',
                'comment' => 'Monday may not be the first day of the week',
            ],
            [
                'param' => 'order_by',
                'value' => 'ORDER BY date_available DESC, file ASC, id ASC',
                'comment' => 'default photo order',
            ],
            [
                'param' => 'order_by_inside_category',
                'value' => 'ORDER BY date_available DESC, file ASC, id ASC',
                'comment' => 'default photo order inside category',
            ],
            [
                'param' => 'original_resize',
                'value' => false,
            ],
            [
                'param' => 'original_resize_maxwidth',
                'value' => 2016,
            ],
            [
                'param' => 'original_resize_maxheight',
                'value' => 2016,
            ],
            [
                'param' => 'original_resize_quality',
                'value' => 95,
            ],
            [
                'param' => 'mobile_theme',
                'value' => null,
            ],
            [
                'param' => 'mail_theme',
                'value' => 'clear',
            ],
            [
                'param' => 'picture_sizes_icon',
                'value' => true,
            ],
            [
                'param' => 'index_sizes_icon',
                'value' => true,
            ],
            [
                'param' => 'index_edit_icon',
                'value' => true,
            ],
            [
                'param' => 'index_caddie_icon',
                'value' => true,
            ],
            [
                'param' => 'display_fromto',
                'value' => false,
            ],
            [
                'param' => 'picture_edit_icon',
                'value' => true,
            ],
            [
                'param' => 'picture_caddie_icon',
                'value' => true,
            ],
            [
                'param' => 'picture_representative_icon',
                'value' => true,
            ],
            [
                'param' => 'show_mobile_app_banner_in_admin',
                'value' => true,
            ],
            [
                'param' => 'show_mobile_app_banner_in_gallery',
                'value' => false,
            ],
            [
                'param' => 'index_search_in_set_button',
                'value' => false,
            ],
            [
                'param' => 'index_search_in_set_action',
                'value' => true,
            ],
            [
                'param' => 'upload_detect_duplicate',
                'value' => true,
            ],
            [
                'param' => 'webmaster_id',
                'value' => 1,
            ],
            [
                'param' => 'use_standard_pages',
                'value' => true,
            ],
        ];
    }
}
