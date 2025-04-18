<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 *                           configuration page
 *
 * Set configuration parameters that are not in the table config. In the
 * application, configuration parameters are considered in the same way
 * coming from config table or config_default.php.
 *
 * It is recommended to let config_default.php as provided and to
 * overwrite configuration in your local configuration file
 * local/config/config.php. See tools/config.php as an example.
 *
 * Why having some parameters in config table and others in
 * config_*.php? Modifying config_*.php is a "hard" task for low
 * skilled users, they need a GUI for this : admin/configuration. But only
 * parameters that might be modified by low skilled users are in config
 * table, other parameters are in config_*.php
 */
class Config
{
    // +-----------------------------------------------------------------------+
    // |                                 misc                                  |
    // +-----------------------------------------------------------------------+

    // order_by_custom and order_by_inside_category_custom : for non common pattern
    // you can define special ORDER configuration
    //
    public ?string $order_by_custom = null; // ' ORDER BY date_available DESC, file ASC, id ASC'

    // order_by_inside_category : inside a category, images can also be ordered
    // by rank. A manually defined rank on each image for the category.
    //
    public ?string $order_by_inside_category_custom;

    // picture_ext : file extensions for picture file, must be a subset of
    // file_ext
    public array $picture_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // file_ext : file extensions (case sensitive) authorized
    //
    // * if you enable "eps" file extension, make sure you have this file type
    //   authorized in your ImageMagick policy
    public array $file_ext;

    // enable_formats: should Piwigo search for multiple formats?
    public bool $enable_formats = false;

    // format_ext : file extensions for formats, ie additional versions of a
    // photo (or nay other file). Formats are in sub-directory pwg_format.
    public array $format_ext = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];

    // top_number : number of element to display for "best rated" and "most
    // visited" categories
    public int $top_number = 15;

    // anti-flood_time : number of seconds between 2 comments : 0 to disable
    public int $anti_flood_time = 60; // anti-flood_time

    // qualified spam comments are not registered (false will register them
    // but they will require admin validation)
    public bool $comment_spam_reject = true;

    // maximum number of links in a comment before it is qualified spam
    public int $comment_spam_max_links = 3;

    // calendar_datefield : date field of table "images" used for calendar
    // catgory
    public string $calendar_datefield = 'date_creation';

    // calendar_show_any : the calendar shows an additional 'any' button in the
    // year/month/week/day navigation bars
    public bool $calendar_show_any = true;

    // calendar_show_empty : the calendar shows month/weeks/days even if there are
    //no elements for these
    public bool $calendar_show_empty = true;

    // newcat_default_commentable : at creation, must a category be commentable
    // or not ?
    public bool $newcat_default_commentable = true;

    // newcat_default_visible : at creation, must a category be visible or not ?
    // Warning : if the parent category is invisible, the category is
    // automatically create invisible. (invisible = locked)
    public bool $newcat_default_visible = true;

    // newcat_default_status : at creation, must a category be public or private
    // ? Warning : if the parent category is private, the category is
    // automatically create private.
    public string $newcat_default_status = 'public';

    // newcat_default_position : at creation, should the album appear at the first or last position ?
    public string $newcat_default_position = 'first';

    // above which number of albums should Piwigo use the lighter album manager
    public int $light_album_manager_threshold = 10_000;

    // level_separator : character string used for separating a category level
    // to the sub level. Suggestions : ' / ', ' &raquo; ', ' &rarr; ', ' - ',
    // ' &gt;'
    public string $level_separator = ' / ';

    // paginate_pages_around : on paginate navigation bar, how many pages
    // display before and after the current page ?
    public int $paginate_pages_around = 2;

    // show_version : shall the version of Piwigo be displayed at the
    // bottom of each page ?
    public bool $show_version = false;

    // meta_ref to reference multiple sets of incorporated pages or elements
    // Set it false to avoid referencing in Google, and other search engines.
    public bool $meta_ref = true;

    // links : list of external links to add in the menu. An example is the best
    // than a long explanation :
    //
    // Simple use:
    //  for each link is associated a label
    //  $conf->links = array(
    //    'http://piwigo.org' => 'PWG website',
    //    'http://piwigo.org/forum' => 'PWG forum',
    //    );
    //
    // Advanced use:
    //  You can also used special options. Instead to pass a string like parameter value
    //  you can pass a array with different optional parameter values
    //  $conf->links = array(
    //    'http://piwigo.org' => array('label' => 'PWG website', 'new_window' => false, 'eval_visible' => 'return true;'),
    //    'http://piwigo.org/forum' => array('label' => 'For ADMIN', 'new_window' => true, 'eval_visible' => 'return is_admin();'),
    //    'http://piwigo.org/ext' => array('label' => 'For Guest', 'new_window' => true, 'eval_visible' => 'return is_a_guest();'),
    //    'http://piwigo.org/downloads' =>
    //      array('label' => 'PopUp', 'new_window' => true,
    //      'nw_name' => 'PopUp', 'nw_features' => 'width=800,height=450,location=no,status=no,toolbar=no,scrollbars=no,menubar=no'),
    //    );
    // Parameters:
    //  'label':
    //    Label to display for the link, must be defined
    //  'new_window':
    //    If true open link on tab/window
    //    [Default value is true if it's not defined]
    //  'nw_name':
    //    Name use when new_window is true
    //    [Default value is '' if it's not defined]
    //  'nw_features':
    //    features use when new_window is true
    //    [Default value is '' if it's not defined]
    //  'eval_visible':
    //    It's php code witch must return if the link is visible or not
    //    [Default value is true if it's not defined]
    //
    // Equivalence:
    //  $conf->links = array(
    //    'http://piwigo.org' => 'PWG website',
    //    );
    //  $conf->links = array(
    //    'http://piwigo.org' => array('label' => 'PWG website', 'new_window' => false, 'visible' => 'return true;'),
    //    );
    //
    // If the array is empty, the "Links" box won't be displayed on the main
    // page.
    public array $links = [];

    // random_index_redirect: list of 'internal' links to use when no section is defined on index.php.
    // An example is the best than a long explanation :
    //
    //  for each link is associated a php condition
    //  '' condition is equivalent to 'return true;'
    //  $conf->random_index_redirect = array(
    //    PHPWG_ROOT_PATH.'index.php?/best_rated' => 'return true;',
    //    PHPWG_ROOT_PATH.'index.php?/recent_pics' => 'return is_a_guest();',
    //    PHPWG_ROOT_PATH.'random.php' => '',
    //    PHPWG_ROOT_PATH.'index.php?/categories' => '',
    //    );
    public array $random_index_redirect = [];

    // List of notes to display on all header page
    // example $conf->header_notes  = array('Test', 'Hello');
    public array $header_notes = [];

    // show_thumbnail_caption : on thumbnails page, show thumbnail captions ?
    public bool $show_thumbnail_caption = true;

    // allow_random_representative : do you wish Piwigo to search among
    // categories elements a new representative at each reload ?
    //
    // If false, an element is randomly or manually chosen to represent its
    // category and remains the representative as long as an admin does not
    // change it.
    //
    // Warning : setting this parameter to true is CPU consuming. Each time you
    // change the value of this parameter from false to true, an administrator
    // must update categories informations in screen [Admin > General >
    // Maintenance].
    public bool $allow_random_representative = false;

    // representative_cache_on_level: if a thumbnail is chosen as representative
    // but has higher privacy level than current user, Piwigo randomly selects
    // another thumbnail. Should be store this thumbnail in cache to avoid
    // another consuming SQL query on next page refresh?
    public bool $representative_cache_on_level = true;

    // representative_cache_on_subcats: if a category (= album) only contains
    // sub-categories, Piwigo randomly selects a thumbnail among sub-categories
    // representative. Should we store this thumbnail in cache to avoid another
    // "slightly" consuming SQL query on next page refresh?
    public bool $representative_cache_on_subcats = true;

    // allow_html_descriptions : authorize administrators to use HTML in
    // category and element description.
    public bool $allow_html_descriptions = true;

    // image level permissions available in the admin interface
    public array $available_permission_levels = [0, 1, 2, 4, 8];

    // check_upgrade_feed: check if there are database upgrade required. Set to
    // true, a message will strongly encourage you to upgrade your database if
    // needed.
    //
    // This configuration parameter is set to true in BSF branch and to false
    // elsewhere.
    public bool $check_upgrade_feed = false;

    // rate_items: available rates for a picture
    public array $rate_items = [0, 1, 2, 3, 4, 5];

    // Define default method to use ('http' or 'html' in order to do redirect)
    public string $default_redirect_method = 'http';

    // Define using double password type in admin's users management panel
    public bool $double_password_type_in_admin = false;

    // Define if logins must be case sensitive or not of user's registration. ie :
    // If set true, the login "user" will equal "User" or "USER" or "user",
    // etc. ... And it will be impossible to use such login variation to create a
    // new user account.
    public bool $insensitive_case_logon = false;

    // how should we check for unicity when adding a photo. Can be 'md5sum' or
    // 'filename'
    public string $uniqueness_mode = 'md5sum';

    // Library used for image resizing. Value could be 'auto', 'imagick',
    // 'ext_imagick', 'gd' or 'vips'. If value is 'auto', library will be chosen in this
    // order. If chosen library is not available, another one will be picked up.
    public string $graphics_library = 'auto';

    // If library used is external installation of ImageMagick ('ext_imagick'),
    // you can define imagemagick directory.
    public string $ext_imagick_dir = '';

    // how many user comments to display by default on comments.php. Use 'all'
    // to display all user comments without pagination. Default available values
    // are array(5,10,20,50,'all') but you can set any other numeric value.
    public int $comments_page_nb_comments = 10;

    // how often should we check for new versions of Piwigo on piwigo.org? In
    // seconds. The check is made only if there are visits on Piwigo.
    // 0 to disable.
    public int $update_notify_check_period = 24 * 60 * 60;

    // how often should be remind of new versions available? For example a first
    // notification was sent on May 5th 2017 for 2.9.1, after how many seconds
    // we send it again? 0 to disable.
    public int $update_notify_reminder_period = 7 * 24 * 60 * 60;

    // should the album description be displayed on all pages (value=true) or
    // only the first page (value=false)
    public bool $album_description_on_all_pages = false;

    // Number of years displayed in the history compare mode (for the years chart)
    public int $stat_compare_year_displayed = 5;

    // Limit for linked albums search
    public int $linked_album_search_limit = 100;

    // how often should we check for missing photos in the filesystem. Only in the
    // administration. Consider the fs_quick_check is always performed on
    // dashboard and maintenance pages. This setting is only for any other
    // administration page.
    // 0 to disable.
    public int $fs_quick_check_period = 24 * 60 * 60;

    // +-----------------------------------------------------------------------+
    // |                                 email                                 |
    // +-----------------------------------------------------------------------+

    // send_bcc_mail_webmaster: send bcc mail to webmaster. Set true for debug
    // or test.
    public bool $send_bcc_mail_webmaster = false;

    // define the name of sender mail: if value is empty, gallery title is used
    public string $mail_sender_name = '';

    // define the email of sender mail: if value is empty, webmaster email is used
    public string $mail_sender_email = '';

    // set true to allow text/html emails
    public bool $mail_allow_html = true;

    // smtp configuration (work if fsockopen function is allowed for smtp port)
    // smtp_host: smtp server host
    //  if null, regular mail function is used
    //   format: hoststring[:port]
    //   exemple: smtp.pwg.net:21
    // smtp_user/smtp_password: user & password for smtp authentication
    public string $smtp_host = '';

    public string $smtp_user = '';

    public string $smtp_password = '';

    // 'ssl' or 'tls'
    public ?string $smtp_secure = null;

    // +-----------------------------------------------------------------------+
    // |                               metadata                                |
    // +-----------------------------------------------------------------------+

    // show_iptc: Show IPTC metadata on picture.php if asked by user
    public bool $show_iptc = false;

    // show_iptc_mapping : is used for showing IPTC metadata on picture.php
    // page. For each key of the array, you need to have the same key in the
    // $lang array. For example, if my first key is 'iptc_keywords' (associated
    // to '2#025') then you need to have $lang['iptc_keywords'] set in
    // language/$user['language']/common.lang.php. If you don't have the lang
    // var set, the key will be simply displayed
    //
    // To know how to associated iptc_field with their meaning, use
    // tools/metadata.php
    public array $show_iptc_mapping = [
        'iptc_keywords' => '2#025',
        'iptc_caption_writer' => '2#122',
        'iptc_byline_title' => '2#085',
        'iptc_caption' => '2#120',
    ];

    // use_iptc: Use IPTC data during database synchronization with files
    // metadata
    public bool $use_iptc = false;

    // use_iptc_mapping : in which IPTC fields will Piwigo find image
    // information ? This setting is used during metadata synchronisation. It
    // associates a piwigo_images column name to a IPTC key
    public array $use_iptc_mapping = [
        'keywords' => '2#025',
        'date_creation' => '2#055',
        'author' => '2#122',
        'name' => '2#005',
        'comment' => '2#120',
    ];

    // show_exif: Show EXIF metadata on picture.php (table or line presentation
    // available)
    public bool $show_exif = true;

    // show_exif_fields : in EXIF fields, you can choose to display fields in
    // sub-arrays, for example ['COMPUTED']['ApertureFNumber']. for this, add
    // 'COMPUTED;ApertureFNumber' in $conf->show_exif_fields
    //
    // The key displayed in picture.php will be $lang['exif_field_Make'] for
    // example and if it exists. For compound fields, only take into account the
    // last part : for key 'COMPUTED;ApertureFNumber', you need
    // $lang['exif_field_ApertureFNumber']
    //
    // for PHP version newer than 4.1.2 :
    // $conf->show_exif_fields = array('CameraMake','CameraModel','DateTime');
    //
    public array $show_exif_fields = [
        'Make',
        'Model',
        'DateTimeOriginal',
        'COMPUTED;ApertureFNumber',
    ];

    // use_exif: Use EXIF data during database synchronization with files
    // metadata
    public bool $use_exif = true;

    // use_exif_mapping: same behaviour as use_iptc_mapping
    public array $use_exif_mapping = [
        'date_creation' => 'DateTimeOriginal',
    ];

    // allow_html_in_metadata: in case the origin of the photo is unsecure (user
    // upload), we remove HTML tags to avoid XSS (malicious execution of
    // javascript)
    public bool $allow_html_in_metadata = false;

    // decide which characters can be used as keyword separators (works in EXIF
    // and IPTC). Coma "," cannot be removed from this list.
    public string $metadata_keyword_separator_regex = '/[.,;]/';

    // +-----------------------------------------------------------------------+
    // |                               sessions                                |
    // +-----------------------------------------------------------------------+

    // session_use_cookies: specifies to use cookie to store
    // the session id on client side
    public bool $session_use_cookies = true;

    // session_use_only_cookies: specifies to only use cookie to store
    // the session id on client side
    public bool $session_use_only_cookies = true;

    // session_use_trans_sid: do not use transparent session id support
    public bool $session_use_trans_sid = false;

    // session_name: specifies the name of the session which is used as cookie name
    public string $session_name = 'pwg_id';

    // session_save_handler: comment the line below
    // to use file handler for sessions.
    public string $session_save_handler = 'db';

    // authorize_remembering : permits user to stay logged for a long time. It
    // creates a cookie on client side.
    public bool $authorize_remembering = true;

    // remember_me_name: specifies the name of the cookie used to stay logged
    public string $remember_me_name = 'pwg_remember';

    // remember_me_length : time of validity for "remember me" cookies, in
    // seconds.
    public int $remember_me_length = 5_184_000;

    // session_length : time of validity for normal session, in seconds.
    public int $session_length = 3600;

    // session_use_ip_address: avoid session hijacking by using a part of the IP
    // address
    public bool $session_use_ip_address = true;

    // Probability, on each page generated, to launch session garbage
    // collector. Integer value between 1 and 100, in %. 0 to disable and let
    // the system default behavior (on Debian-like, it's "never delete
    // session").
    public int $session_gc_probability = 1;

    // +-----------------------------------------------------------------------+
    // |                            debug/performance                          |
    // +-----------------------------------------------------------------------+

    // number of photos beyond which individual photos are added in the
    // lounge, a temporary zone where photos wait before being "launched".
    // 50k photos by default.
    public int $lounge_activate_threshold = 1;

    // Lounge is automatically emptied (photos are being pushed to their
    // albums) when the oldest one reaches this duration. Lounge can be emptied
    // before, either manually or at the end of the upload. In seconds.
    // 5 minutes by default.
    public int $lounge_max_duration = 5 * 60;

    // show_queries : for debug purpose, show queries and execution times
    public bool $show_queries = false;

    // show_gt : display generation time at the bottom of each page
    public bool $show_gt = true; // false

    // debug_l10n : display a warning message each time an unset language key is
    // accessed
    public bool $debug_l10n = false;

    // activate template debugging - a new window will appear
    public bool $debug_template = false;

    // save copies of sent mails into local data dir
    public bool $debug_mail = false;

    // die_on_sql_error: if an SQL query fails, should everything stop?
    public bool $die_on_sql_error = false;

    // if true, some language strings are replaced during template compilation
    // (instead of template output). this results in better performance. however
    // any change in the language file will not be propagated until you purge
    // the compiled templates from the admin / maintenance menu
    public bool $compiled_template_cache_language = false;

    // This tells Smarty whether to check for recompiling or not. Recompiling
    // does not need to happen unless a template is changed. false results in
    // better performance.
    public bool $template_compile_check = true;

    // This forces Smarty to (re)compile templates on every invocation. This is
    // handy for development and debugging. It should never be used in a
    // production environment.
    public bool $template_force_compile = true; // false

    // activate merging of javascript / css files
    public bool $template_combine_files = false; // true

    // this permit to show the php errors reporting (see INI 'error_reporting'
    // for possible values)
    // gives an empty value '' to deactivate
    public string $show_php_errors = ''; // E_ALL;

    // This sets the display_errors php option to true, so php errors and warning
    // messages are shown in the browser. If this is false, the error messages are
    // available in the php log of the server if show_php_errors has any set.
    // If the below is turned off in local config and errors are still shown on
    // frontend, check for display_errors setting server's php config
    public bool $show_php_errors_on_frontend = false; // true;

    // +-----------------------------------------------------------------------+
    // |                            authentication                             |
    // +-----------------------------------------------------------------------+

    // apache_authentication : use Apache authentication as reference instead of
    // users table ?
    public bool $apache_authentication = false;

    // If you decide to use external authentication
    // change conf below by $conf->external_authentication = true;
    public bool $external_authentication = false;

    // user_fields : mapping between generic field names and table specific
    // field names. For example, in PWG, the mail address is names
    // "mail_address" and in punbb, it's called "email".
    public array $user_fields = [
        'id' => 'id',
        'username' => 'username',
        'password' => 'password',
        'email' => 'mail_address',
    ];

    // password_hash: function hash the clear user password to store it in the
    // database. The function takes only one parameter: the clear password.
    public string $password_hash = '\Piwigo\inc\functions_user::pwg_password_hash';

    // password_verify: function that checks the password against its hash. The
    // function takes 2 mandatory parameter : clear password, hashed password +
    // an optional parameter user_id. The user_id is used to update the password
    // with the new hash introduced in Piwigo 2.5. See function
    // pwg_password_verify in inc/functions_user.php
    public string $password_verify = '\Piwigo\inc\functions_user::pwg_password_verify';

    // guest_id : id of the anonymous user
    public int $guest_id = 2;

    // default_user_id : id of user used for default value
    public int $default_user_id;

    // Registering process and guest/generic members get language from the browser
    // if language isn't available PHPWG_DEFAULT_LANGUAGE is used as previously
    public bool $browser_language = true;

    // webmaster_id : webmaster'id.
    public int $webmaster_id = 1;

    // does the guest have access ?
    // (not a security feature, set your categories "private" too)
    // If false it'll be redirected from index.php to identification.php
    public bool $guest_access = true;

    // +-----------------------------------------------------------------------+
    // |                               history                                 |
    // +-----------------------------------------------------------------------+

    // nb_logs_page :  how many logs to display on a page
    public int $nb_logs_page = 300;

    // Every X new line in history, perform an automatic purge. The more often,
    // the fewer lines to delete. 0 to disable.
    public int $history_autopurge_every = 1021;

    // How many lines to keep in history on autopurge? 0 to disable.
    public int $history_autopurge_keep_lines = 1_000_000;

    // On history autopurge, how many lines should to deleted at once, maximum?
    public int $history_autopurge_blocksize = 50_000;

    // +-----------------------------------------------------------------------+
    // |                                 urls                                  |
    // +-----------------------------------------------------------------------+

    // gallery_url : you can set a specific URL for the home page of your
    // gallery. This is for very specific use and you don't need to change this
    // setting when move your gallery to a new directory or a new domain name.
    public ?string $gallery_url = null;

    // question_mark_in_urls : the generated urls contain a ? sign. This can be
    // changed to false only if the server translates PATH_INFO variable
    // (depends on the server AcceptPathInfo directive configuration)
    public bool $question_mark_in_urls = true;

    // php_extension_in_urls : if false, the urls generated for picture and
    // category will not contain the .php extension. This will work only if
    // .htaccess defines Options +MultiViews parameter or url rewriting rules
    // are active.
    public bool $php_extension_in_urls = true;

    // category_url_style : one of 'id' (default) or 'id-name'. 'id-name'
    // means that an simplified ascii representation of the category name will
    // appear in the url
    public string $category_url_style = 'id';

    // picture_url_style : one of 'id' (default), 'id-file' or 'file'. 'id-file'
    // or 'file' mean that the file name (without extension will appear in the
    // url). Note that one additional sql query will occur if 'file' is chosen.
    // Note that you might experience navigation issues if you choose 'file'
    // and your file names are not unique
    public string $picture_url_style = 'id';

    // tag_url_style : one of 'id-tag' (default), 'id' or 'tag'.
    // Note that if you choose 'tag' and the url (ascii) representation of your
    // tags is not unique, all tags with the same url representation will be shown
    public string $tag_url_style = 'id-tag';

    // force an explicit port in the url (like ":80" or ":443")
    // * 'none' : do not add any port, whatever protocol is detected
    // * 'auto' : tries to smartly add a port based on $_SERVER variables
    // * 123 : adds ":123" next to url host
    public string $url_port = 'none';

    // +-----------------------------------------------------------------------+
    // |                                 tags                                  |
    // +-----------------------------------------------------------------------+

    // full_tag_cloud_items_number: number of tags to show in the full tag
    // cloud. Only the most represented tags will be shown
    public int $full_tag_cloud_items_number = 200;

    // menubar_tag_cloud_items_number: number of tags to show in the tag
    // cloud in the menubar. Only the most represented tags will be shown
    public int $menubar_tag_cloud_items_number = 20;

    // menubar_tag_cloud_content: 'always_all', 'current_only' or 'all_or_current'
    // For the tag cloud in the menubar.
    // 'always_all': tag cloud always displays all tags available to the user
    // 'current_only': tag cloud always displays the tags from the current pictures
    // 'all_or_current': when pictures are displayed, tag cloud shows their tags, but
    // when none are displayed, all the tags available to the user are shown.
    public string $menubar_tag_cloud_content = 'all_or_current';

    // content_tag_cloud_items_number: number of related tags to show in the tag
    // cloud on the content page, when the current section is not a set of
    // tags. Only the most represented tags will be shown
    public int $content_tag_cloud_items_number = 12;

    // tags_levels: number of levels to use for display. Each level is bind to a
    // CSS class tagLevelX.
    public int $tags_levels = 5;

    // tags_default_display_mode: group tags by letter or display a tag cloud by
    // default? 'letters' or 'cloud'.
    public string $tags_default_display_mode = 'cloud';

    // tag_letters_column_number: how many columns to display tags by letter
    public int $tag_letters_column_number = 4;

    // +-----------------------------------------------------------------------+
    // | Related albums                                                        |
    // +-----------------------------------------------------------------------+

    // beyond this limit, do not try to find related albums. If there are too
    // many items, the SQL query will be slow and the results irrelevant,
    // because showing too many related albums.
    public int $related_albums_maximum_items_to_compute = 1000;

    // once found the related albums, how many to show in the menubar? We take
    // the heaviest (with more relations).
    public int $related_albums_display_limit = 20;

    // +-----------------------------------------------------------------------+
    // | Notification by mail                                                  |
    // +-----------------------------------------------------------------------+

    // Default Value for nbm user
    public bool $nbm_default_value_user_enabled = false;

    // Search list user to send quickly (List all without to check news)
    // More quickly but less fun to use
    public bool $nbm_list_all_enabled_users_to_send = false;

    // Max time used on one pass in order to send mails.
    // Timeout delay ratio.
    public float $nbm_max_treatment_timeout_percent = 0.8;

    // If timeout cannot be combined with nbm_max_treatment_timeout_percent,
    // nbm_treatment_timeout_default is used by default
    public int $nbm_treatment_timeout_default = 20;

    // Parameters used in get_recent_post_dates for the 2 kind of notification
    public array $recent_post_dates = [
        'RSS' => [
            'max_dates' => 5,
            'max_elements' => 6,
            'max_cats' => 6,
        ],
        'NBM' => [
            'max_dates' => 7,
            'max_elements' => 3,
            'max_cats' => 9,
        ],
    ];

    // the author shown in the RSS feed <author> element
    public string $rss_feed_author = 'Piwigo notifier';

    // how long does the authentication key stays valid, in seconds. 3 days by
    // default. 0 to disable.
    public int $auth_key_duration = 3 * 24 * 60 * 60;

    // +-----------------------------------------------------------------------+
    // | Set admin layout                                                      |
    // +-----------------------------------------------------------------------+

    public string $admin_theme = 'roma'; // clear

    // should we load the active plugins ? true=Yes, false=No
    public bool $enable_plugins = true;

    // Web services are allowed (true) or completely forbidden (false)
    public bool $allow_web_services = true;

    // Maximum number of images to be returned foreach call to the web service
    public int $ws_max_images_per_page = 500;

    // Maximum number of users to be returned foreach call to the web service
    public int $ws_max_users_per_page = 1000;

    // Display a link to subscribe to Piwigo Announcements Newsletter
    public bool $show_newsletter_subscription = true;

    // Fetch and show latest news from piwigo.org
    public bool $show_piwigo_latest_news = true;

    // Check for available updates on Piwigo or extensions, performed each time
    // the dashboard is displayed
    public bool $dashboard_check_for_updates = true;

    // Number Weeks displayed on activity chart on the dashboard
    public int $dashboard_activity_nb_weeks = 4;

    // On the Admin>Users>Activity page, should we display the connection/disconnections?
    // 'all' = do not filter, display all
    // 'admins_only' = only display connections of admin users
    // 'none' = don't even display connections of admin users
    public string $activity_display_connections = 'admins_only';

    // On album mover page, number of seconds before auto openning album when
    // dragging an album. In milliseconds. 3 seconds by default.
    public int $album_move_delay_before_auto_opening = 3 * 1000;

    // This variable is used to show or hide the template tab in the side menu
    public bool $show_template_in_side_menu = false;

    // Add last calculated cache size to Dashboard Storage chart if true.
    // To recalculate use Tools -> Maintenance, Refresh.
    // To disable, set to false.
    public bool $add_cache_to_storage_chart = true;

    // +-----------------------------------------------------------------------+
    // | Filter                                                                |
    // +-----------------------------------------------------------------------+

    // $conf->filter_pages contains configuration for each pages
    //   o If values are not defined for a specific page, default value are used
    //   o Array is composed by the basename of each page without extension
    //   o List of value names:
    //     - used: filter function are used
    //       (if false nothing is done [start, cancel, stop, ...]
    //     - cancel: cancel current started filter
    //     - add_notes: add notes about current started filter on the header
    //   o Empty configuration in order to disable completely filter functions
    //     No filter, No icon,...
    //     $conf->filter_pages = array();
    public array $filter_pages = [
        // Default page
        'default' => [
            'used' => true,
            'cancel' => false,
            'add_notes' => false,
        ],
        // Real pages
        'index' => [
            'add_notes' => true,
        ],
        'tags' => [
            'add_notes' => true,
        ],
        'search' => [
            'add_notes' => true,
        ],
        'comments' => [
            'add_notes' => true,
        ],
        'admin' => [
            'used' => false,
        ],
        'feed' => [
            'used' => false,
        ],
        'notification' => [
            'used' => false,
        ],
        'nbm' => [
            'used' => false,
        ],
        'popuphelp' => [
            'used' => false,
        ],
        'profile' => [
            'used' => false,
        ],
        'ws' => [
            'used' => false,
        ],
        'identification' => [
            'cancel' => true,
        ],
        'install' => [
            'cancel' => true,
        ],
        'password' => [
            'cancel' => true,
        ],
        'register' => [
            'cancel' => true,
        ],
    ];

    // +-----------------------------------------------------------------------+
    // | Slideshow                                                             |
    // +-----------------------------------------------------------------------+

    // slideshow_period : waiting time in seconds before loading a new page
    // during automated slideshow
    // slideshow_period_min, slideshow_period_max are bounds of slideshow_period
    // slideshow_period_step is the step of navigation between min and max
    public int $slideshow_period_min = 1;

    public int $slideshow_period_max = 10;

    public int $slideshow_period_step = 1;

    public int $slideshow_period = 4;

    // slideshow_repeat : slideshow loops on pictures
    public bool $slideshow_repeat = true;

    // $conf->light_slideshow indicates to use slideshow.tpl in state of
    // picture.tpl for slideshow
    // Take care to have slideshow.tpl in all available templates
    // Or set it false.
    // Check if Picture's plugins are compliant with it
    // Every plugin from 1.7 would be design to manage light_slideshow case.
    public bool $light_slideshow = true;

    // the local data directory is used to store data such as compiled templates,
    // plugin variables, combined css/javascript or resized images. Beware of
    // mandatory trailing slash.
    public string $data_location = '_data/';

    // where should the API/UploadForm add photos? This path must be relative to
    // the Piwigo installation directory (but can be outside, as long as it's
    // reachable from your webserver).
    public string $upload_dir = './upload';

    // where should the user be guided when there is no photo in his gallery yet?
    public string $no_photo_yet_url = 'admin.php?page=photos_add';

    // directory with themes inside
    public string $themes_dir = PHPWG_ROOT_PATH . 'themes';

    // enable the synchronization method for adding photos
    public bool $enable_synchronization = true;

    // enable the update of Piwigo core from administration pages
    public bool $enable_core_update = true;

    // enable install/update of plugins/themes/languages from administration pages
    public bool $enable_extensions_install = true;

    // Permitted characters for files/directories during synchronization.
    // Do not add the ' U+0027 single quote apostrophe character, it WILL make some
    // SQL queries fail. URI reserved characters (see
    // https://tools.ietf.org/html/rfc3986#section-2.2 ) MAY make things fail, this
    // is known for example for the & character leading to a query parameter
    // separator if the resulting URI path is not urlencoded. Adding accented
    // characters or characters of Unicode letter or digit classes in the basic
    // plane *usually* are fine iff the file system's names *and* the config file
    // content are both UTF-8 encoded, as is the MySQL database table, and the file
    // system does not use decomposed Unicode characters for accented characters.
    //
    // Possible expressions could be:
    // * Just add the space character:
    //   $conf->sync_chars_regex = '/^[a-zA-Z0-9-_. ]+$/';
    // * Add space character and German umlauts and sharp s (sz) (note this is
    //   UTF-8 encoded, if you see "odd" sequences then the encoding in your viewer
    //   or editor is wrong, and maybe your file system is as well), and
    //   parentheses and brackets; also note the trailing 'u' regex option to have
    //   PHP interpret the expression as UTF-8 string instead of ASCII:
    //   $conf->sync_chars_regex = '/^[a-zA-Z0-9-_. äÄöÖüÜßẞ()\[\]]+$/u';
    // * Allow all Unicode letter and numeric and whitespace characters (largely
    //   encoding independent but still might have quirks with file system's file
    //   name encoding) and parentheses and brackets; again with the 'u' regex
    //   option to let PHP match Unicode characters and properties:
    //   $conf->sync_chars_regex = '/^[-_.\p{L}\p{N}\p{Z}()\[\]]+$/u';
    // You may try your expression at https://regex101.com/ choosing the
    // PCRE2 (PHP >=7.3) flavor.
    // See also:
    // https://www.regular-expressions.info/unicode.html
    // https://www.regular-expressions.info/php.html#preg
    // https://www.php.net/manual/en/pcre.pattern.php
    //
    // The default expression is restrictive but safe and sane ASCII only
    // alphanumeric and hyphen-minus and underscore and dot.
    public string $sync_chars_regex = '/^[a-zA-Z0-9-_.]+$/';

    // folders name excluded during synchronization
    public array $sync_exclude_folders = [];

    // PEM url (default is http://piwigo.org/ext)
    public string $alternative_pem_url = '';

    // categories ID on PEM
    public int $pem_plugins_category = 12;

    public int $pem_themes_category = 10;

    public int $pem_languages_category = 8;

    // based on the EXIF "orientation" tag, should we rotate photos added in the
    // upload form or through pwg.images.addSimple web API method?
    public bool $upload_form_automatic_rotation = true;

    // 0-'auto', 1-'derivative' 2-'script'
    public int $derivative_url_style = 0;

    public int $chmod_value;

    // 'small', 'medium' or 'large'
    public string $derivative_default_size = 'medium';

    // below which size (in pixels, ie width*height) do we remove metadata
    // EXIF/IPTC... from derivative?
    public int $derivatives_strip_metadata_threshold = 256_000;

    // For animated webP files, to avoid heavy derivatives, set a specific quality,
    // different from derivatives.resize_quality
    public int $animated_webp_compression_quality = 70;

    //Maximum Ajax requests at once, for thumbnails on-the-fly generation
    public int $max_requests = 3;

    // one of '', 'images', 'all'
    //TODO: Put this in admin and also manage .htaccess in #sites and upload folders
    public string $original_url_protection = '';

    // Default behaviour when a new album is created: should the new album inherit the group/user
    // permissions from its parent? Note that config is only used for Ftp synchro,
    // and if that option is not explicitly transmit when the album is created.
    public bool $inheritance_by_default = false;

    // 'png' or 'jpg': your uploaded TIF photos will have a representative in
    // JPEG or PNG file format
    public string $tiff_representative_ext = 'png';

    // in the upload form, let users upload only picture_exts or all file_exts?
    // for some file types, Piwigo will try to generate a pwg_representative
    // (TIFF, videos, PDF)
    public bool $upload_form_all_types = false;

    // Size of chunks, in kilobytes. Fast connections will have better
    // performances with high values, such as 5000.
    public int $upload_form_chunk_size = 500;

    // Maximum size for a file in the upload form, in megabytes.
    public int $upload_form_max_file_size = 1000;

    // If we try to generate a pwg_representative for a video we use ffmpeg. If
    // "ffmpeg" is not visible by the web user, you can define the full path of
    // the directory where "ffmpeg" executable is.
    public string $ffmpeg_dir = '';

    // batch manager: how many images should Piwigo display by default on the
    // global mode. Must be among values {20,50,100}
    public int $batch_manager_images_per_page_global = 20;

    // batch manager: how many images should Piwigo display by default on the
    // unit mode. Must be among values {5, 10, 50}
    public int $batch_manager_images_per_page_unit = 5;

    // how many missing md5sum should Piwigo compute at once.
    public int $checksum_compute_blocksize = 50;

    // quicksearch engine: include all photos from sub-albums of any matching
    // album. For example, if search is "bear", then we display photos from
    // "bear/grizzly". When value changed, delete database cache files in
    // _data/cache directory
    public bool $quick_search_include_sub_albums = false;

    // +-----------------------------------------------------------------------+
    // |                                 log                                   |
    // +-----------------------------------------------------------------------+

    // Logs directory, relative to $conf->data_location
    public string $log_dir = '/logs';

    // Log level (EMERGENCY, ALERT, CRITICAL, ERROR, WARNING, NOTICE, INFO, DEBUG)
    // development = DEBUG, production = ERROR
    public string $log_level = \Psr\Log\LogLevel::DEBUG;

    // Keep logs file during X days
    public int $log_archive_days = 30;

    // +-----------------------------------------------------------------------+
    // | Proxy Settings                                                        |
    // +-----------------------------------------------------------------------+

    // If piwigo needs a http-proxy to connect to the internet, set this to true
    public bool $use_proxy = false;

    // Connection string of the proxy
    public string $proxy_server = 'proxy.domain.org:port';

    // If the http-proxy requires authentication, set username and password here
    // e.g. username:password
    public string $proxy_auth = '';

    // +-----------------------------------------------------------------------+
    // | other properties                                                      |
    // +-----------------------------------------------------------------------+

    public bool $activate_comments;

    public array $AdminTools;

    public bool $allow_user_customization;

    public bool $allow_user_registration;

    public string $blk_menubar;

    public bool $bootstrap_darkroom_core_js_in_header;

    public string $bootstrap_darkroom_navbar_contextual_bg;

    public string $bootstrap_darkroom_navbar_contextual_style;

    public string $bootstrap_darkroom_navbar_main_bg;

    public string $bootstrap_darkroom_navbar_main_style;

    public string $bootstrap_darkroom;

    public array $bootstrap_darkroom_ps_exif_replacements;

    public ?array $c13y_ignore = null;

    public array $cache_sizes;

    public bool $comments_author_mandatory;

    public bool $comments_email_mandatory;

    public bool $comments_enable_website;

    public bool $comments_forall;

    public string $comments_order;

    public bool $comments_validation;

    public bool|int $count_orphans;

    public int $data_dir_checked;

    public string $db_base;

    public string $db_host;

    public string $db_password;

    public string $db_user;

    public string $sql_backend;

    public string $dblayer {
        set {
            $this->dblayer = $value;
            $this->sql_backend = '\Piwigo\inc\dblayer\functions_' . $value;
        }
    }

    public array $derivatives;

    public array|string $disabled_derivatives;

    public bool $display_fromto;

    public array $elegant;

    public bool $email_admin_on_comment_deletion;

    public bool $email_admin_on_comment_edition;

    public bool $email_admin_on_comment_validation;

    public bool $email_admin_on_comment;

    public string $email_admin_on_new_user;

    public ?string $empty_lounge_running = null;

    public array|string $extents_for_templates;

    public array $flip_file_ext;

    public array $flip_picture_ext;

    public string $fs_quick_check_last_check;

    public bool $gallery_locked;

    public string $gallery_title;

    public array $gdThumb;

    public bool $history_admin;

    public bool $history_guest;

    public array $history_sections_cache;

    public ?bool $history_summarized_dropped = null;

    public bool $index_caddie_icon;

    public bool $index_created_date_icon;

    public bool $index_edit_icon;

    public bool $index_flat_icon;

    public bool $index_new_icon;

    public bool $index_posted_date_icon;

    public bool $index_search_in_set_action;

    public bool $index_search_in_set_button;

    public bool $index_sizes_icon;

    public bool $index_slideshow_icon;

    public bool $index_sort_order_input;

    public bool $log;

    public bool $lounge_active;

    public string $mail_theme;

    public bool $menubar_filter_icon;

    public string $mobile_theme;

    public array $modus_theme;

    public ?bool $multiview_invalidate_cache = null;

    public int $nb_categories_page;

    public int $nb_comment_page;

    public string $nbm_complementary_mail_content;

    public bool $nbm_send_detailed_content;

    public bool $nbm_send_html_mail;

    public string $nbm_send_mail_as;

    public bool $nbm_send_recent_post_dates;

    public ?bool $never_delete_originals = null;

    public ?array $no_flag_languages = null;

    public bool|string $no_photo_yet;

    public bool $obligatory_user_mail_address;

    public string $order_by_inside_category;

    public string $order_by;

    public int $original_resize_maxheight;

    public int $original_resize_maxwidth;

    public int $original_resize_quality;

    public bool $original_resize;

    public array $LocalFilesEditor_tabs;

    public string $page_banner;

    public bool $picture_caddie_icon;

    public bool $picture_download_icon;

    public bool $picture_edit_icon;

    public bool $picture_favorite_icon;

    public array $picture_information;

    public bool $picture_menu;

    public bool $picture_metadata_icon;

    public bool $picture_navigation_icons;

    public bool $picture_navigation_thumb;

    public bool $picture_representative_icon;

    public bool $picture_sizes_icon;

    public bool $picture_slideshow_icon;

    public int|string $piwigo_db_version;

    public string $piwigo_installed_version;

    public bool $rate_anonymous;

    public bool $rate;

    public string $secret_key;

    public bool $show_mobile_app_banner_in_admin;

    public bool $show_mobile_app_banner_in_gallery;

    public array $smartpocket;

    public array $TakeATour_tour_ignored;

    public string $update_notify_last_check;

    public array $update_notify_last_notification;

    public array $updates_ignored;

    public bool $upload_detect_duplicate;

    public bool $user_can_delete_comment;

    public bool $user_can_edit_comment;

    public string $week_starts_on;

    // ----------------------------------------------------------

    public function __construct()
    {
        $this->order_by_inside_category_custom = $this->order_by_custom;

        $this->file_ext = array_merge(
            $this->picture_ext,
            ['tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic']
        );

        $this->default_user_id = $this->guest_id;

        $this->chmod_value = substr_compare(PHP_SAPI, 'apa', 0, 3) == 0 ? 0777 : 0755;
    }
}
