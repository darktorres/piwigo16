<?php

declare(strict_types=1);

use Piwigo\Admin\Install\InstallDefaultConfig;

/**
 * Pinned/golden comparison against the former `install/config.sql` (now
 * deleted) -- $expectedJson below is the exact raw SQL literal text every
 * row's `value` column held before this class replaced it (a JSON column,
 * so the SQL file's own hand-encoded JSON-text literal was already
 * byte-identical to what json_encode() of the equivalent native PHP value
 * produces). Transcribed by hand while writing InstallDefaultConfig::rows()
 * itself, verified against the real file one more time immediately before
 * it was deleted.
 *
 * @return array<string, string|null>
 */
function installDefaultConfigExpectedJson(): array
{
    return [
        'activate_comments' => 'false',
        'nb_comment_page' => '10',
        'log' => 'true',
        'comments_validation' => 'false',
        'comments_forall' => 'false',
        'comments_order' => '"ASC"',
        'comments_author_mandatory' => 'false',
        'comments_email_mandatory' => 'false',
        'comments_enable_website' => 'true',
        'user_can_delete_comment' => 'false',
        'user_can_edit_comment' => 'false',
        'email_admin_on_comment_edition' => 'false',
        'email_admin_on_comment_deletion' => 'false',
        'gallery_locked' => 'false',
        'gallery_title' => '""',
        'rate' => 'false',
        'rate_anonymous' => 'true',
        'page_banner' => '""',
        'history_admin' => 'false',
        'history_guest' => 'true',
        'allow_user_registration' => 'true',
        'allow_user_customization' => 'true',
        'nb_categories_page' => '12',
        'nbm_send_html_mail' => 'true',
        'nbm_send_mail_as' => '""',
        'nbm_send_detailed_content' => 'true',
        'nbm_complementary_mail_content' => '""',
        'nbm_send_recent_post_dates' => 'true',
        'email_admin_on_new_user' => '"none"',
        'email_admin_on_comment' => 'false',
        'email_admin_on_comment_validation' => 'true',
        'obligatory_user_mail_address' => 'false',
        'menubar_filter_icon' => 'false',
        'index_sort_order_input' => 'true',
        'index_flat_icon' => 'false',
        'index_posted_date_icon' => 'true',
        'index_created_date_icon' => 'true',
        'index_slideshow_icon' => 'true',
        'index_new_icon' => 'true',
        'picture_metadata_icon' => 'true',
        'picture_slideshow_icon' => 'true',
        'picture_favorite_icon' => 'true',
        'picture_download_icon' => 'true',
        'picture_navigation_icons' => 'true',
        'picture_navigation_thumb' => 'true',
        'picture_menu' => 'false',
        'picture_informations' => '{"author":true,"created_on":true,"posted_on":true,"dimensions":false,"file":false,"filesize":false,"tags":true,"categories":true,"visits":true,"rating_score":true,"privacy_level":true}',
        'week_starts_on' => '"monday"',
        'order_by' => '"ORDER BY date_available DESC, file ASC, id ASC"',
        'order_by_inside_category' => '"ORDER BY date_available DESC, file ASC, id ASC"',
        'original_resize' => 'false',
        'original_resize_maxwidth' => '2016',
        'original_resize_maxheight' => '2016',
        'original_resize_quality' => '95',
        'mobile_theme' => null,
        'mail_theme' => '"clear"',
        'picture_sizes_icon' => 'true',
        'index_sizes_icon' => 'true',
        'index_edit_icon' => 'true',
        'index_caddie_icon' => 'true',
        'display_fromto' => 'false',
        'picture_edit_icon' => 'true',
        'picture_caddie_icon' => 'true',
        'picture_representative_icon' => 'true',
        'show_mobile_app_banner_in_admin' => 'true',
        'show_mobile_app_banner_in_gallery' => 'false',
        'index_search_in_set_button' => 'false',
        'index_search_in_set_action' => '"true"',
        'upload_detect_duplicate' => 'true',
        'webmaster_id' => '1',
        'use_standard_pages' => 'true',
    ];
}

/**
 * @return array<string, string|null>
 */
function installDefaultConfigExpectedComments(): array
{
    return [
        'activate_comments' => 'Global parameter for usage of comments system',
        'nb_comment_page' => 'number of comments to display on each page',
        'log' => 'keep an history of visits on your website',
        'comments_validation' => 'administrators validate users comments before becoming visible',
        'comments_forall' => 'even guest not registered can post comments',
        'comments_order' => 'comments order on picture page and cie',
        'comments_author_mandatory' => 'Comment author is mandatory',
        'comments_email_mandatory' => 'Comment email is mandatory',
        'comments_enable_website' => 'Enable "website" field on add comment form',
        'user_can_delete_comment' => 'administrators can allow user delete their own comments',
        'user_can_edit_comment' => 'administrators can allow user edit their own comments',
        'email_admin_on_comment_edition' => 'Send an email to the administrators when a comment is modified',
        'email_admin_on_comment_deletion' => 'Send an email to the administrators when a comment is deleted',
        'gallery_locked' => 'Lock your gallery temporary for non admin users',
        'gallery_title' => 'Title at top of each page and for RSS feed',
        'rate' => 'Rating pictures feature is enabled',
        'rate_anonymous' => 'Rating pictures feature is also enabled for visitors',
        'page_banner' => 'html displayed on the top each page of your gallery',
        'history_admin' => 'keep a history of administrator visits on your website',
        'history_guest' => 'keep a history of guest visits on your website',
        'allow_user_registration' => 'allow visitors to register?',
        'allow_user_customization' => 'allow users to customize their gallery?',
        'nb_categories_page' => 'Param for categories pagination',
        'nbm_send_html_mail' => 'Send mail on HTML format for notification by mail',
        'nbm_send_mail_as' => 'Send mail as param value for notification by mail',
        'nbm_send_detailed_content' => 'Send detailed content for notification by mail',
        'nbm_complementary_mail_content' => 'Complementary mail content for notification by mail',
        'nbm_send_recent_post_dates' => 'Send recent post by dates for notification by mail',
        'email_admin_on_new_user' => 'Send an email to theadministrators when a user registers',
        'email_admin_on_comment' => 'Send an email to the administrators when a valid comment is entered',
        'email_admin_on_comment_validation' => 'Send an email to the administrators when a comment requires validation',
        'obligatory_user_mail_address' => 'Mail address is obligatory for users',
        'menubar_filter_icon' => 'Display filter icon',
        'index_sort_order_input' => 'Display image order selection list',
        'index_flat_icon' => 'Display flat icon',
        'index_posted_date_icon' => 'Display calendar by posted date',
        'index_created_date_icon' => 'Display calendar by creation date icon',
        'index_slideshow_icon' => 'Display slideshow icon',
        'index_new_icon' => 'Display new icons next albums and pictures',
        'picture_metadata_icon' => 'Display metadata icon on picture page',
        'picture_slideshow_icon' => 'Display slideshow icon on picture page',
        'picture_favorite_icon' => 'Display favorite icon on picture page',
        'picture_download_icon' => 'Display download icon on picture page',
        'picture_navigation_icons' => 'Display navigation icons on picture page',
        'picture_navigation_thumb' => 'Display navigation thumbnails on picture page',
        'picture_menu' => 'Show menubar on picture page',
        'picture_informations' => 'Information displayed on picture page',
        'week_starts_on' => 'Monday may not be the first day of the week',
        'order_by' => 'default photo order',
        'order_by_inside_category' => 'default photo order inside category',
    ];
}

test('rows() has exactly 71 rows with unique params, matching the former install/config.sql row count', function (): void {
    $rows = InstallDefaultConfig::rows();

    expect($rows)
        ->toHaveCount(71);

    $params = array_column($rows, 'param');
    expect($params)
        ->toBe(array_unique($params));
});

test('rows() reproduces every value exactly as install/config.sql\'s own JSON-text literal encoded it', function (): void {
    $expected = installDefaultConfigExpectedJson();

    foreach (InstallDefaultConfig::rows() as $row) {
        expect(array_key_exists($row['param'], $expected))
            ->toBeTrue("unexpected param '{$row['param']}' not present in the pinned comparison");

        $expectedJson = $expected[$row['param']];
        $actualJson = $row['value'] === null ? null : json_encode($row['value']);

        expect($actualJson)
            ->toBe($expectedJson, "value mismatch for param '{$row['param']}'");
    }

    // Every expected param was actually produced -- catches a row silently
    // dropped from rows(), which the loop above (keyed off rows() itself)
    // can't detect on its own.
    $actualParams = array_column(InstallDefaultConfig::rows(), 'param');
    expect($actualParams)
        ->toEqualCanonicalizing(array_keys($expected));
});

test('rows() carries the same comment text as install/config.sql for every row that had one', function (): void {
    $expectedComments = installDefaultConfigExpectedComments();

    foreach (InstallDefaultConfig::rows() as $row) {
        $expectedComment = $expectedComments[$row['param']] ?? null;
        expect($row['comment'] ?? null)
            ->toBe($expectedComment, "comment mismatch for param '{$row['param']}'");
    }
});
