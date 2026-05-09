<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Exception\AuthException;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Menu\BlockManager;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final class MiscController
{
    /** @var list<string> */
    public const array PAGES = [
        'notification_by_mail', 'permalinks', 'tags', 'help', 'popuphelp',
        'intro', 'menubar', 'index', 'comments', 'rating', 'rating_user', 'profile',
    ];

    private bool $mustRepost = false;

    public function handle(string $page): void
    {
        if ($page === 'notification_by_mail') {
            $this->notificationByMail();
        } elseif ($page === 'permalinks') {
            $this->permalinks();
        } elseif ($page === 'tags') {
            $this->tags();
        } elseif ($page === 'help') {
            $this->help();
        } elseif ($page === 'popuphelp') {
            $this->popupHelp();
        } elseif ($page === 'intro') {
            $this->intro();
        } elseif ($page === 'menubar') {
            $this->menubar();
        } elseif ($page === 'index') {
            $this->index();
        } elseif ($page === 'comments') {
            $this->comments();
        } elseif ($page === 'rating') {
            $this->rating();
        } elseif ($page === 'rating_user') {
            $this->ratingUser();
        } elseif ($page === 'profile') {
            $this->profile();
        }
    }

    // ── notification_by_mail ──────────────────────────────────────────────────

    private function notificationByMail(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        MailNotificationContext::init();

        ServiceLocator::get(Util::class)->checkInputParameter('mode', $_GET, false, '/^(param|subscribe|send)$/');

        $GLOBALS['base_url'] = $base_url = ServiceLocator::get(UrlGenerator::class)->admin();
        $this->mustRepost = false;

        if (!isset($_GET['mode']) || !is_string($_GET['mode'])) {
            $page['mode'] = 'send';
        } else {
            $page['mode'] = $_GET['mode'];
        }

        PermissionService::get()->checkStatus($this->getTabStatus($page['mode']));

        EventDispatcher::addListener('nbm_render_global_customize_mail_content', $this->renderGlobalCustomizeMailContent(...));
        EventDispatcher::notify('nbm_event_handler_added');

        if (count($_POST) == 0) {
            $this->insertNewDataUserMailNotification($base_url);
        }

        if (!empty($_POST)) {
            ServiceLocator::get(Util::class)->checkPwgToken();
        }

        switch ($page['mode']) {
            case 'param':
                if (isset($_POST['param_submit'])) {
                    $nbmSendMailAsRaw = $_POST['nbm_send_mail_as'] ?? null;
                    $_POST['nbm_send_mail_as'] = strip_tags(is_string($nbmSendMailAsRaw) ? $nbmSendMailAsRaw : '');
                    ServiceLocator::get(Util::class)->checkInputParameter('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
                    ServiceLocator::get(Util::class)->checkInputParameter('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
                    ServiceLocator::get(Util::class)->checkInputParameter('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');
                    $updated_param_count = 0;
                    foreach (ServiceLocator::get(Connection::class)->executeQuery('SELECT param, value FROM ' . Tables::config() . " WHERE param LIKE 'nbm\\_%'")->fetchAllAssociative() as $nbm_user) {
                        $param = is_string($nbm_user['param'] ?? null) ? $nbm_user['param'] : '';
                        if (isset($_POST[$param])) {
                            /** @var string $rawParamVal */
                            $rawParamVal = $_POST[$param];
                            ServiceLocator::get(ConfigService::class)->confUpdateParam($param, $rawParamVal, true);
                            $updated_param_count++;
                        }
                    }
                    $tpl->assign(['save_success' => Translator::get()->plural('%d parameter was updated.', '%d parameters were updated.', $updated_param_count)]);
                }
                // fall through
                // no break
            case 'subscribe':
                if (isset($_POST['falsify']) && isset($_POST['cat_true'])) {
                    $rawCatTrue2 = $_POST['cat_true'];
                    $cat_true = is_array($rawCatTrue2) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatTrue2) : [];
                    $check_key_treated = ServiceLocator::get(NotificationAdminService::class)->unsubscribeNotificationByMail(true, $cat_true);
                    if ($this->doTimeoutTreatment('cat_true', $check_key_treated)) {
                        $this->mustRepost = true;
                    }
                } elseif (isset($_POST['trueify']) && isset($_POST['cat_false'])) {
                    $rawCatFalse2 = $_POST['cat_false'];
                    $cat_false = is_array($rawCatFalse2) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatFalse2) : [];
                    $check_key_treated = ServiceLocator::get(NotificationAdminService::class)->subscribeNotificationByMail(true, $cat_false);
                    if ($this->doTimeoutTreatment('cat_false', $check_key_treated)) {
                        $this->mustRepost = true;
                    }
                }
                break;
            case 'send':
                if (isset($_POST['send_submit']) && isset($_POST['send_selection']) && isset($_POST['send_customize_mail_content'])) {
                    $rawSendSelection = $_POST['send_selection'];
                    $send_selection = is_array($rawSendSelection) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawSendSelection) : [];
                    $rawCustomMail = $_POST['send_customize_mail_content'];
                    $check_key_treated = $this->doActionSendMailNotification('send', $send_selection, stripslashes(is_string($rawCustomMail) ? $rawCustomMail : ''));
                    $check_key_treated_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $check_key_treated);
                    if ($this->doTimeoutTreatment('send_selection', $check_key_treated_str)) {
                        $this->mustRepost = true;
                    }
                }
                break;
        }

        $tpl->setFilenames(['double_select' => 'double_select.tpl', 'notification_by_mail' => 'notification_by_mail.tpl']);
        $tpl->assign(['PWG_TOKEN' => ServiceLocator::get(Util::class)->getPwgToken(), 'U_HELP' => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('notification_by_mail'), 'F_ACTION' => $base_url . UrlService::get()->getQueryStringDiff([])]);

        if (PermissionService::get()->isAutorizeStatus(AccessLevel::Webmaster)) {
            $tabsheet = new Tabsheet();
            $tabsheet->setId('nbm');
            $tabsheet->select($page['mode']);
            $tabsheet->assign();
        }

        if ($this->mustRepost) {
            $repost_submit_name = '';
            if (isset($_POST['falsify'])) {
                $repost_submit_name = 'falsify';
            } elseif (isset($_POST['trueify'])) {
                $repost_submit_name = 'trueify';
            } elseif (isset($_POST['send_submit'])) {
                $repost_submit_name = 'send_submit';
            }
            $tpl->assign('REPOST_SUBMIT_NAME', $repost_submit_name);
        }

        switch ($page['mode']) {
            case 'param':
                $tpl->assign($page['mode'], ['SEND_HTML_MAIL' => Config::nbmSendHtmlMail(), 'SEND_MAIL_AS' => Config::nbmSendMailAs(), 'SEND_DETAILED_CONTENT' => Config::nbmSendDetailedContent(), 'COMPLEMENTARY_MAIL_CONTENT' => Config::nbmComplementaryMailContent(), 'SEND_RECENT_POST_DATES' => Config::nbmSendRecentPostDates()]);
                break;
            case 'subscribe':
                $tpl->assign($page['mode'], true);
                $tpl->assign(['L_CAT_OPTIONS_TRUE' => Lang::t('Subscribed'), 'L_CAT_OPTIONS_FALSE' => Lang::t('Unsubscribed')]);
                $data_users = ServiceLocator::get(NotificationAdminService::class)->getUserNotifications('subscribe');
                $opt_true = $opt_true_selected = $opt_false = $opt_false_selected = [];
                $rawCatTruePost  = $_POST['cat_true']  ?? null;
                $rawCatFalsePost = $_POST['cat_false'] ?? null;
                $cat_true_post  = is_array($rawCatTruePost) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatTruePost) : [];
                $cat_false_post = is_array($rawCatFalsePost) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatFalsePost) : [];
                foreach ($data_users as $nbm_user) {
                    $ck = (string) $nbm_user['check_key'];
                    if (BoolUtil::fromMixed($nbm_user['enabled'])) {
                        $opt_true[$ck] = stripslashes((string) $nbm_user['username']) . '[' . (string) $nbm_user['mail_address'] . ']';
                        if (isset($_POST['falsify']) && in_array($ck, $cat_true_post)) {
                            $opt_true_selected[] = $ck;
                        }
                    } else {
                        $opt_false[$ck] = stripslashes((string) $nbm_user['username']) . '[' . (string) $nbm_user['mail_address'] . ']';
                        if (isset($_POST['trueify']) && in_array($ck, $cat_false_post)) {
                            $opt_false_selected[] = $ck;
                        }
                    }
                }
                $tpl->assign(['category_option_true' => $opt_true, 'category_option_true_selected' => $opt_true_selected, 'category_option_false' => $opt_false, 'category_option_false_selected' => $opt_false_selected]);
                $tpl->assignVarFromHandle('DOUBLE_SELECT', 'double_select');
                break;
            case 'send':
                $tpl_var    = ['users' => []];
                $data_users = $this->doActionSendMailNotification('list_to_send');
                $rawCustomMailContent = $_POST['send_customize_mail_content'] ?? null;
                $tpl_var['CUSTOMIZE_MAIL_CONTENT'] = isset($_POST['send_customize_mail_content']) ? stripslashes(is_string($rawCustomMailContent) ? $rawCustomMailContent : '') : Config::nbmComplementaryMailContent();
                $rawSendSelPost = $_POST['send_selection'] ?? null;
                $send_sel_post = is_array($rawSendSelPost) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawSendSelPost) : [];
                if (count($data_users)) {
                    foreach ($data_users as $nbm_user_raw) {
                        if (!is_array($nbm_user_raw)) {
                            continue;
                        }
                        $checkKey = is_scalar($nbm_user_raw['check_key'] ?? null) ? (string) $nbm_user_raw['check_key'] : '';
                        if (!$this->mustRepost || in_array($checkKey, $send_sel_post)) {
                            $tpl_var['users'][] = ['ID' => $checkKey, 'CHECKED' => (isset($_POST['send_selection']) && !in_array($checkKey, $send_sel_post)) ? '' : 'checked="checked"', 'USERNAME' => stripslashes(is_scalar($nbm_user_raw['username'] ?? null) ? (string) $nbm_user_raw['username'] : ''), 'EMAIL' => $nbm_user_raw['mail_address'] ?? '', 'LAST_SEND' => $nbm_user_raw['last_send'] ?? null];
                        }
                    }
                }
                $tpl->assign($page['mode'], $tpl_var);
                if (Config::authKeyDuration() > 0) {
                    $strMiscResult = strtotime('now -' . Config::authKeyDuration() . ' second');
                    $tpl->assign('auth_key_duration', ServiceLocator::get(DateService::class)->timeSince($strMiscResult !== false ? $strMiscResult : null, 'second', null, false));
                }
                break;
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Send mail to users'));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'notification_by_mail');
    }

    // ── permalinks ────────────────────────────────────────────────────────────

    private function permalinks(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        ServiceLocator::get(Util::class)->checkInputParameter('cat_id', $_POST, false, ValidationPattern::ID);

        $selected_cat = [];
        if (isset($_POST['set_permalink']) && $_POST['cat_id'] > 0) {
            ServiceLocator::get(Util::class)->checkPwgToken();
            $permalinkRaw = $_POST['permalink'] ?? null;
            $permalink  = is_string($permalinkRaw) ? $permalinkRaw : '';
            $rawPostCatId = $_POST['cat_id'];
            $postCatId  = is_string($rawPostCatId) ? $rawPostCatId : '';
            if (empty($permalink)) {
                ServiceLocator::get(PermalinkService::class)->deleteCatPermalink($postCatId, isset($_POST['save']));
            } else {
                ServiceLocator::get(PermalinkService::class)->setCatPermalink($postCatId, $permalink, isset($_POST['save']));
            }
            $selected_cat = [(int) $postCatId];
        } elseif (isset($_GET['delete_permanent'])) {
            ServiceLocator::get(Util::class)->checkPwgToken();
            $rawDeletePermanent = $_GET['delete_permanent'];
            $deleted = ServiceLocator::get(PermalinkRepository::class)->deleteOldPermalinkByValue(is_string($rawDeletePermanent) ? $rawDeletePermanent : '');
            if (!$deleted) {
                PageState::current()->addError(Lang::t('Cannot delete the old permalink !'));
            }
        }

        $tpl->setFilename('permalinks', 'permalinks.tpl');
        $page['tab'] = 'permalinks';
        ServiceLocator::get(AlbumsTabRenderer::class)->render();

        $query = 'SELECT id, permalink, CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name, uppercats, global_rank FROM ' . Tables::categories();
        ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query, $selected_cat, 'categories', false);

        $pwg_token = ServiceLocator::get(Util::class)->getPwgToken();

        $sort_by = $this->parseSortVariables(['id', 'name', 'permalink'], 'name', 'psf', ['delete_permanent'], 'SORT_');
        $sortBy0  = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
        $permalinkQuery = 'SELECT id, permalink, uppercats, global_rank FROM ' . Tables::categories() . ' WHERE permalink IS NOT NULL';
        if ($sortBy0 === 'id' || $sortBy0 === 'permalink') {
            $permalinkQuery .= ' ORDER BY ' . $sortBy0;
        }
        $categories = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($permalinkQuery)->fetchAllAssociative() as $row) {
            $row['name'] = ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache(is_scalar($row['uppercats'] ?? null) ? (string) $row['uppercats'] : '');
            $categories[] = $row;
        }
        if ($sort_by[0] == 'name') {
            usort($categories, ServiceLocator::get(CategoryService::class)->globalRankCompare(...));
        }
        $tpl->assign('permalinks', $categories);

        $sort_by = $this->parseSortVariables(['cat_id', 'permalink', 'date_deleted', 'last_hit', 'hit'], null, 'dpsf', ['delete_permanent'], 'SORT_OLD_', '#old_permalinks');
        $url_del_base    = ServiceLocator::get(UrlGenerator::class)->admin('permalinks');
        $sortByOld0      = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
        $oldPermalinkQuery = 'SELECT * FROM ' . Tables::oldPermalinks();
        if (count($sort_by) && $sortByOld0 !== '') {
            $oldPermalinkQuery .= ' ORDER BY ' . $sortByOld0;
        }
        $deleted_permalinks = [];
        foreach (ServiceLocator::get(Connection::class)->executeQuery($oldPermalinkQuery)->fetchAllAssociative() as $row) {
            $row['name']     = ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache((string) (is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0));
            $row['U_DELETE'] = UrlService::get()->addUrlParams($url_del_base, ['delete_permanent' => $row['permalink'], 'pwg_token' => $pwg_token]);
            $deleted_permalinks[] = $row;
        }

        $tpl->assign(['PWG_TOKEN' => $pwg_token, 'U_HELP' => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('permalinks'), 'deleted_permalinks' => $deleted_permalinks, 'ADMIN_PAGE_TITLE' => Lang::t('Albums'), 'page_data_json' => json_encode(['nb_cats' => count($categories)], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)]);
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'permalinks');
    }

    // ── tags ──────────────────────────────────────────────────────────────────

    private function tags(): void
    {
        $tpl = TemplateRegistry::current();

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet    = new Tabsheet();
        $tabsheet->setId('tags');
        $tabsheet->select('');
        $tabsheet->assign();

        if (isset($_GET['action']) && 'delete_orphans' == $_GET['action']) {
            ServiceLocator::get(Util::class)->checkPwgToken();
            ServiceLocator::get(TagAdminService::class)->deleteOrphanTags();
            $_SESSION['message_tags'] = Lang::t('Orphan tags deleted');
            Util::get()->redirect(ServiceLocator::get(UrlGenerator::class)->admin('tags'));
        }

        $tpl->setFilenames(['tags' => 'tags.tpl']);
        $tpl->assign(['F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin('tags'), 'PWG_TOKEN' => ServiceLocator::get(Util::class)->getPwgToken(), 'BATCH_MANAGER_URL' => ServiceLocator::get(UrlGenerator::class)->admin('batch_manager')]);

        $warning_tags     = '';
        $orphan_tags      = ServiceLocator::get(TagAdminService::class)->getOrphanTags();
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tag_name       = is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '';
            $orphan_tag_names[] = EventDispatcher::dispatch('render_tag_name', $tag_name, $tag);
        }

        $orphan_tag_names_array = '[]';
        if (count($orphan_tag_names) > 0) {
            $warning_tags = sprintf(Lang::t('You have %d orphan tags %s'), count($orphan_tag_names), '<a class="icon-eye" data-url="' . ServiceLocator::get(UrlGenerator::class)->admin('tags') . '&amp;action=delete_orphans&amp;pwg_token=' . ServiceLocator::get(Util::class)->getPwgToken() . '">' . Lang::t('Review') . '</a>');
            $orphan_tag_names_array = '["' . implode('" ,"', array_map(htmlentities(...), $orphan_tag_names, array_fill(0, count($orphan_tag_names), ENT_QUOTES))) . '"]';
        }
        $tpl->assign(['orphan_tag_names_array' => $orphan_tag_names_array, 'warning_tags' => $warning_tags]);

        $message_tags = '';
        if (isset($_SESSION['message_tags'])) {
            $rawMessageTags = $_SESSION['message_tags'];
            $message_tags   = is_string($rawMessageTags) ? $rawMessageTags : '';
            unset($_SESSION['message_tags']);
        }
        $tpl->assign('message_tags', $message_tags);

        $per_page   = 100;
        $_tagRepo   = ServiceLocator::get(TagRepository::class);
        $tag_counters = $_tagRepo->getTagCounters();
        $all_tags   = [];
        foreach ($_tagRepo->findAll() as $tag) {
            $raw_name       = $tag['name'];
            $tag['raw_name'] = $raw_name;
            $tag['name']    = EventDispatcher::dispatch('render_tag_name', $raw_name, $tag);
            $tagIdRaw       = $tag['id'] ?? null;
            $tag_id_key     = is_string($tagIdRaw) ? $tagIdRaw : '';
            $counter        = is_numeric($tag_counters[$tag_id_key] ?? null) ? (int) $tag_counters[$tag_id_key] : 0;
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }
            $tagNameStr     = is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '';
            $alt_names      = array_diff(array_unique(array_filter(EventDispatcher::dispatch('get_tag_alt_names', [], $raw_name), is_string(...))), [$tagNameStr]);
            if (count($alt_names)) {
                $tag['alt_names'] = implode(', ', $alt_names);
            }
            $all_tags[] = $tag;
        }
        usort($all_tags, ServiceLocator::get(HtmlService::class)->tagAlphaCompare(...));

        $tpl->assign(['first_tags' => array_slice($all_tags, 0, $per_page), 'data' => $all_tags, 'total' => count($all_tags), 'per_page' => $per_page, 'ADMIN_PAGE_TITLE' => Lang::t('Tags')]);
        $tpl->assign('page_data_json', json_encode([
            'pwg_token' => ServiceLocator::get(Util::class)->getPwgToken(), 'total' => count($all_tags), 'orphan_tag_names' => $orphan_tag_names,
            'str_already_exist' => Lang::t('Tag "%s" already exists'), 'str_and_others_tags' => Lang::t('and %s others'), 'str_clear_selection' => Lang::t('Clear Selection'), 'str_copy' => Lang::t(' (copy)'), 'str_delete' => Lang::t('Delete tag "%s"?'), 'str_delete_orphan_tags' => Lang::t('Delete orphan tags ?'), 'str_delete_tags' => Lang::t('Delete tags {%s}?'), 'str_delete_them' => Lang::t('Delete them'), 'str_keep_them' => Lang::t('Keep them'), 'str_merged_into' => Lang::t('Tag(s) {%s1} succesfully merged into "%s2"'), 'str_no_delete_confirmation' => Lang::t('No, I have changed my mind'), 'str_no_photos' => Lang::t('no photo'), 'str_number_photos' => Lang::t('%d photos'), 'str_orphan_tags' => Lang::t('You have %s1 orphan : %s2'), 'str_other_copy' => Lang::t(' (copy %s)'), 'str_select_all_tag' => Lang::t('Select all %d tags'), 'str_selection_done' => Lang::t('The %d tags on this page are selected'), 'str_tag_created' => Lang::t('Tag "%s" created'), 'str_tag_deleted' => Lang::t('Tag "%s" succesfully deleted'), 'str_tag_found' => Lang::t('<b>%d</b> tag found'), 'str_tag_rename' => Lang::t('Rename "%s"'), 'str_tag_selected' => Lang::t('<b>%d</b> tag selected'), 'str_tags_deleted' => Lang::t('Tags {%s} succesfully deleted'), 'str_tags_found' => Lang::t('<b>%d</b> tags found'), 'str_yes_delete_confirmation' => Lang::t('Yes, delete'), 'str_yes_rename_confirmation' => Lang::t('Yes, rename'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'tags');
    }

    // ── help ──────────────────────────────────────────────────────────────────

    private function help(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];

        $selected = isset($_GET['section']) && is_string($_GET['section']) ? $_GET['section'] : 'add_photos';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('help');
        $tabsheet->select($selected);
        $tabsheet->assign();

        EventDispatcher::notify('loc_end_help');

        $tpl->setFilenames(['help' => 'help.latte']);
        $tpl->assign([
            'HELP_CONTENT'       => LangService::get()->loadLanguage('help/help_' . $tabsheet->selected . '.html', '', ['return' => true]),
            'HELP_SECTION_TITLE' => $tabsheet->sheets[$tabsheet->selected]['caption'] ?? '',
        ]);

        $language_prefix = substr(is_scalar($user['language'] ?? null) ? (string) $user['language'] : '', 0, 3);
        if ('en_' == $language_prefix) {
            PageState::current()->addMessage(sprintf('Need help to use Piwigo? <a href="%s" target="_blank">Check the online documentation</a> !', 'https://doc.piwigo.org/'));
        } elseif ('fr_' == $language_prefix) {
            PageState::current()->addMessage(sprintf('Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !', 'https://doc-fr.piwigo.org/'));
        }

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'help');
    }

    // ── popuphelp ─────────────────────────────────────────────────────────────

    private function popupHelp(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        defined('PWG_HELP') or define('PWG_HELP', true);

        if (!isset($_GET['output']) || 'content_only' != $_GET['output']) {
            $page['body_id']    = 'thePopuphelpPage';
            $title              = Lang::t('Piwigo Help');
            $page['page_banner'] = '<h1>' . $title . '</h1>';
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
            $tpl->assign(['U_RETURN' => '', 'USERNAME' => '', 'U_FAQ' => '', 'U_CHANGE_THEME' => '', 'U_LOGOUT' => '']);
            PageHeaderRenderer::render($title);
        }

        $rawHelpPage = $_GET['help'] ?? null;
        $helpPage = is_string($rawHelpPage) ? $rawHelpPage : '';
        if (isset($_GET['help']) && preg_match('/^[a-z_]*$/', $helpPage)) {
            $help_content = LangService::get()->loadLanguage('help/' . $helpPage . '.html', '', ['force_fallback' => 'en_UK', 'return' => true]);
            if ($help_content == false) {
                $help_content = '';
            }
            $help_content = EventDispatcher::dispatch('get_popup_help_content', $help_content, $_GET['help']);
        } else {
            throw new AuthException('Hacking attempt!');
        }

        $tpl->setFilename('popuphelp', 'popuphelp.tpl');
        $tpl->assign(['HELP_CONTENT' => $help_content]);

        if (isset($_GET['output']) && 'content_only' == $_GET['output']) {
            echo $help_content;
            exit();
        }

        $tpl->pparse('popuphelp');
        PageTailRenderer::render();
    }

    // ── intro ─────────────────────────────────────────────────────────────────

    private function intro(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        /** @var array<string, mixed> $pwg_loaded_plugins */
        $pwg_loaded_plugins = is_array($GLOBALS['pwg_loaded_plugins'] ?? null) ? $GLOBALS['pwg_loaded_plugins'] : [];

        if (isset($_GET['action']) && 'hide_newsletter_subscription' == $_GET['action']) {
            PreferencesService::get()->userprefsUpdateParam('show_newsletter_subscription', 'false');
            exit();
        }

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet    = new Tabsheet();
        $tabsheet->setId('admin_home');
        $tabsheet->select('');
        $tabsheet->assign();

        if (isset($page['nb_pending_comments'])) {
            $message = Lang::t('User comments') . ' <i class="icon-chat"></i> ';
            $message .= '<a href="' . $my_base_url . 'comments">';
            $nbPending = $page['nb_pending_comments'];
            $message .= Lang::t('%d waiting for validation', is_numeric($nbPending) ? (int) $nbPending : 0);
            $message .= ' <i class="icon-right"></i></a>';
            PageState::current()->addMessage($message);
        }

        $nb_orphans = is_numeric($page['nb_orphans'] ?? null) ? (int) $page['nb_orphans'] : 0;
        if (is_numeric($page['nb_photos_total'] ?? null) && (int) $page['nb_photos_total'] >= 100000) {
            $nb_orphans = ServiceLocator::get(ImageAdminService::class)->countOrphans();
        }

        if ($nb_orphans > 0) {
            $orphans_url = ServiceLocator::get(UrlGenerator::class)->admin('batch_manager') . '&amp;filter=prefilter-no_album';
            $message     = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>' . Lang::t('Orphans') . '</a><span class="adminMenubarCounter">' . $nb_orphans . '</span>';
            PageState::current()->addWarning($message);
        }

        $locked_album = ServiceLocator::get(CategoryRepository::class)->countHidden();
        if ($locked_album > 0) {
            $locked_album_url = ServiceLocator::get(UrlGenerator::class)->admin('cat_options') . '&section=visible';
            $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>' . Lang::t('Locked album') . '</a><span class="adminMenubarCounter">' . $locked_album . '</span>';
            PageState::current()->addWarning($message);
        }

        ServiceLocator::get(ImageAdminService::class)->fsQuickCheck();

        $tpl->setFilenames(['intro' => 'intro.tpl']);

        $intro_newsletter_data = null;
        if (Config::showNewsletterSubscription() && PreferencesService::get()->userprefsGetParam('show_newsletter_subscription', true)) {
            $register_date = ServiceLocator::get(UserRepository::class)->findEarliestRegistrationDate();
            $nb_cats       = ServiceLocator::get(CategoryRepository::class)->countAll();
            $nb_images     = ServiceLocator::get(ImageRepository::class)->countAll();
            $uagent_obj    = new \uagent_info();
            if (!$uagent_obj->DetectIos() && strtotime((string) $register_date) < strtotime('2 weeks ago') && $nb_cats >= 3 && $nb_images >= 30) {
                $userLang  = is_string($user['language'] ?? null) ? $user['language'] : '';
                $userEmail = is_string($user['email'] ?? null) ? $user['email'] : '';
                $intro_newsletter_data = ['email' => $userEmail, 'subscribe_base_url' => ServiceLocator::get(AdminService::class)->getNewsletterSubscribeBaseUrl($userLang), 'old_newsletters_url' => ServiceLocator::get(AdminService::class)->getOldNewslettersBaseUrl($userLang), 'str_subscribe_title' => Lang::t('Subscribe to our newsletter and stay updated!'), 'str_subscribe_button' => Lang::t('Sign up to the newsletter'), 'str_see_previous' => Lang::t('See previous newsletters'), 'str_dismiss' => Lang::t('Understood, do not show again')];
            }
        }

        $stats      = ServiceLocator::get(AdminService::class)->getPwgGeneralStatitics();
        $du_decimals = 1;
        $du_gb      = (is_numeric($stats['disk_usage']) ? (float) $stats['disk_usage'] : 0.0) / (1024.0 * 1024.0);
        if ($du_gb > 100) {
            $du_decimals = 0;
        }

        $tpl->assign(['NB_PHOTOS' => $stats['nb_photos'], 'NB_ALBUMS' => $stats['nb_categories'], 'NB_TAGS' => $stats['nb_tags'], 'NB_IMAGE_TAG' => $stats['nb_image_tag'], 'NB_USERS' => $stats['nb_users'], 'NB_GROUPS' => $stats['nb_groups'], 'NB_RATES' => $stats['nb_rates'], 'NB_VIEWS' => ServiceLocator::get(AdminService::class)->numberFormatHumanReadable(is_numeric($stats['nb_views']) ? (float) $stats['nb_views'] : 0.0), 'NB_PLUGINS' => count($pwg_loaded_plugins), 'STORAGE_USED' => str_replace(' ', '&nbsp;', Lang::t('%sGB', number_format($du_gb, $du_decimals))), 'U_QUICK_SYNC' => ServiceLocator::get(UrlGenerator::class)->admin('site_update') . '&amp;site=1&amp;quick_sync=1&amp;pwg_token=' . ServiceLocator::get(Util::class)->getPwgToken(), 'CHECK_FOR_UPDATES' => Config::dashboardCheckForUpdates()]);

        if (Config::activateComments()) {
            $tpl->assign('NB_COMMENTS', ServiceLocator::get(CommentRepository::class)->countAll());
        } else {
            $tpl->assign('NB_COMMENTS', 0);
        }

        if (Config::showPiwigoLatestNews()) {
            $latest_news = ServiceLocator::get(AdminService::class)->getPiwigoNews();
            if (isset($latest_news['id']) && $latest_news['posted_on'] > time() - 60 * 60 * 24 * 30) {
                $newsUrl     = $latest_news['url'] ?? null;
                $newsPosted  = $latest_news['posted'] ?? null;
                $newsSubject = $latest_news['subject'] ?? null;
                PageState::current()->addMessage(sprintf('%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>', Lang::t('Latest Piwigo news'), is_string($newsUrl) ? $newsUrl : '', ServiceLocator::get(DateService::class)->timeSince(is_string($latest_news['posted_on']) || is_int($latest_news['posted_on']) ? $latest_news['posted_on'] : null, 'year') . ' (' . (is_string($newsPosted) ? $newsPosted : '') . ')', is_string($newsSubject) ? $newsSubject : ''));
            }
        }

        EventDispatcher::notify('loc_end_intro');

        $nb_weeks         = Config::dashboardActivityNbWeeks();
        $mondays          = 0;
        $week_number      = [];
        $temp_data        = [];
        $activity_last_weeks = [];
        $date             = new \DateTime();

        while ($mondays < $nb_weeks) {
            if ($date->format('D') == 'Mon') {
                $week_number[] = $date->format('W');
                $mondays += 1;
            }
            $date->sub(new \DateInterval('P1D'));
        }
        $week_number = array_reverse($week_number);
        $date_string = $date->format('Y-m-d');

        $cached_activity = is_array($_SESSION['cache_activity_last_weeks'] ?? null) ? $_SESSION['cache_activity_last_weeks'] : null;
        if ($cached_activity === null || (is_numeric($cached_activity['calculated_on']) ? (int) $cached_activity['calculated_on'] : 0) < strtotime('5 minutes ago')) {
            $start_time = StringUtil::get()->getMoment();
            $activity_actions = DbConnection::get()->executeQuery("SELECT DATE_FORMAT(occured_on , '%Y-%m-%d') AS activity_day, object, action, COUNT(*) AS activity_counter FROM `" . Tables::activity() . "` WHERE occured_on >= '" . $date_string . "' GROUP BY activity_day, object, action;")->fetchAllAssociative();

            foreach ($activity_actions as $action) {
                $day_date = new \DateTime((is_string($action['activity_day'] ?? null) ? $action['activity_day'] : '') . ' 12:00:00');
                $week     = 0;
                for ($i = 0; $i < $nb_weeks; $i++) {
                    if ($week_number[$i] == $day_date->format('W')) {
                        $week = $i;
                    }
                }
                $day_nb = $day_date->format('N');
                $activity_last_weeks[$week][$day_nb]['details'][ucfirst(is_string($action['object'] ?? null) ? $action['object'] : '')][ucfirst(is_string($action['action'] ?? null) ? $action['action'] : '')] = $action['activity_counter'];
                $activity_last_weeks[$week][$day_nb]['number'] = ($activity_last_weeks[$week][$day_nb]['number'] ?? 0) + (is_numeric($action['activity_counter']) ? (int) $action['activity_counter'] : 0);
                $activity_last_weeks[$week][$day_nb]['date']   = ServiceLocator::get(DateService::class)->formatDate($day_date->getTimestamp());
            }

            LoggerRegistry::current()->debug('[admin/intro::] recent activity calculated in ' . StringUtil::get()->getElapsedTime($start_time, StringUtil::get()->getMoment()));
            $_SESSION['cache_activity_last_weeks'] = ['calculated_on' => time(), 'data' => $activity_last_weeks];
        }

        $cached_activity     = is_array($_SESSION['cache_activity_last_weeks'] ?? null) ? $_SESSION['cache_activity_last_weeks'] : [];
        $activity_last_weeks = is_array($cached_activity['data'] ?? null) ? $cached_activity['data'] : [];

        foreach ($activity_last_weeks as $week => $i) {
            if (!is_array($i)) {
                continue;
            }
            foreach ($i as $day => $j) {
                if (!is_array($j)) {
                    continue;
                }
                $details = is_array($j['details'] ?? null) ? $j['details'] : [];
                ksort($details);
                if (is_array($activity_last_weeks[$week] ?? null) && is_array($activity_last_weeks[$week][$day] ?? null)) {
                    /** @var array<string, mixed> $dayEntry */
                    $dayEntry = $activity_last_weeks[$week][$day];
                    $dayEntry['details'] = $details;
                    $activity_last_weeks[$week][$day] = $dayEntry;
                }
                $jNumber = is_numeric($j['number'] ?? null) ? (int) $j['number'] : 0;
                if ($jNumber > 0) {
                    $temp_data[] = ['x' => $jNumber, 'd' => $day, 'w' => $week];
                }
            }
        }

        usort($temp_data, $this->cmpDay(...));

        $diff_x = [];
        for ($i = 1; $i < count($temp_data); $i++) {
            $diff_x[] = (float) $temp_data[$i]['x'] / (float) $temp_data[$i - 1]['x'] * 100.0;
        }
        $split = 0;
        if (count($diff_x) > 0) {
            while (max($diff_x) > 120) {
                $diff_x[array_search(max($diff_x), $diff_x)] = -1;
                $split++;
            }
        }

        $chart_data = [];
        for ($i = 0; $i < $nb_weeks; $i++) {
            for ($j = 1; $j <= 7; $j++) {
                $chart_data[$i][$j] = 0;
            }
        }
        $size = 1;
        if (isset($temp_data[0])) {
            $chart_data[$temp_data[0]['w']][$temp_data[0]['d']] = $size;
        }
        for ($i = 1; $i < count($temp_data); $i++) {
            if ($diff_x[$i - 1] == -1) {
                $size++;
            } $chart_data[$temp_data[$i]['w']][$temp_data[$i]['d']] = $size;
        }

        $tpl->assign('ACTIVITY_WEEK_NUMBER', $week_number);
        $tpl->assign('ACTIVITY_LAST_WEEKS', $activity_last_weeks);
        $tpl->assign('ACTIVITY_CHART_DATA', $chart_data);
        $tpl->assign('ACTIVITY_CHART_NUMBER_SIZES', $size);

        /** @var array<string, mixed> $lang */
        $lang      = is_array($GLOBALS['lang']) ? $GLOBALS['lang'] : [];
        $day_names = is_array($lang['day'] ?? null) ? $lang['day'] : [];
        $day_labels = [];
        for ($i = 0; $i <= 6; $i++) {
            $name       = $day_names[($i + 1) % 7] ?? '';
            $day_labels[] = mb_substr(is_string($name) ? $name : '', 0, 3);
        }
        $tpl->assign('DAY_LABELS', $day_labels);

        $video_format = ['webm', 'webmv', 'ogg', 'ogv', 'mp4', 'm4v', 'mov'];
        $data_storage = [];
        foreach (array_column(DbConnection::get()->executeQuery("SELECT COUNT(*) AS ext_counter, SUBSTRING_INDEX(path,'.',-1) AS ext, SUM(filesize) AS filesize FROM `" . Tables::images() . '` GROUP BY ext;')->fetchAllAssociative(), null, 'ext') as $ext => $ext_details) {
            $type = in_array(strtolower((string) $ext), Config::pictureExtensions()) ? 'Photos' : (in_array(strtolower((string) $ext), $video_format) ? 'Videos' : 'Other');
            $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + (is_numeric($ext_details['filesize']) ? (int) $ext_details['filesize'] : 0);
            $data_storage[$type]['total']['nb_files']  = ($data_storage[$type]['total']['nb_files'] ?? 0) + (is_numeric($ext_details['ext_counter']) ? (int) $ext_details['ext_counter'] : 0);
            $data_storage[$type]['details'][strtoupper((string) $ext)] = ['filesize' => $ext_details['filesize'], 'nb_files' => $ext_details['ext_counter']];
        }
        foreach (array_column(DbConnection::get()->executeQuery('SELECT COUNT(*) AS ext_counter, ext, SUM(filesize) AS filesize FROM `' . Tables::imageFormat() . '` GROUP BY ext;')->fetchAllAssociative(), null, 'ext') as $ext => $ext_details) {
            $type = 'Formats';
            $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + (is_numeric($ext_details['filesize']) ? (int) $ext_details['filesize'] : 0);
            $data_storage[$type]['total']['nb_files']  = ($data_storage[$type]['total']['nb_files'] ?? 0) + (is_numeric($ext_details['ext_counter']) ? (int) $ext_details['ext_counter'] : 0);
            $data_storage[$type]['details'][strtoupper((string) $ext)] = ['filesize' => $ext_details['filesize'], 'nb_files' => $ext_details['ext_counter']];
        }

        if (Config::addCacheToStorageChart() && Config::has('cache_sizes')) {
            $cache_sizes = unserialize((string) Config::cacheSizes());
            if (is_array($cache_sizes) && isset($cache_sizes[0]) && is_array($cache_sizes[0]) && isset($cache_sizes[0]['value'])) {
                $cacheFilesize = (is_numeric($cache_sizes[0]['value']) ? (float) $cache_sizes[0]['value'] : 0.0) / 1024.0;
                $data_storage['Cache'] = ['total' => ['filesize' => $cacheFilesize, 'nb_files' => 0], 'details' => []];
            }
        }

        $total_storage = 0.0;
        foreach ($data_storage as $value) {
            $total_storage += (float) $value['total']['filesize'];
        }

        $tpl->assign('STORAGE_TOTAL', $total_storage);
        $tpl->assign('STORAGE_CHART_DATA', $data_storage);

        $translate_type = [];
        foreach ($data_storage as $type => $_unused) {
            $translate_type[$type] = Lang::t($type);
        }

        $intro_dashboard_extras = ['check_for_updates' => Config::dashboardCheckForUpdates(), 'storage_total' => $total_storage, 'str_gb_used' => Lang::t('%s GB used'), 'str_mb_used' => Lang::t('%s MB used'), 'str_piwigo_need_update' => Lang::t('A new version of Piwigo is available.'), 'str_ext_need_update' => Lang::t('Some upgrades are available for extensions.')];
        if ($intro_newsletter_data !== null) {
            $intro_dashboard_extras['newsletter'] = $intro_newsletter_data;
        }

        $tpl->assign('page_data_json', json_encode(['storage_details' => $data_storage, 'str_gb' => Lang::t('%sGB'), 'str_mb' => Lang::t('%sMB'), 'translate_type' => $translate_type, 'translate_files' => Lang::t('%d files'), 'dashboard' => $intro_dashboard_extras], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'intro');

        $c13y = new CheckIntegrity();
        new C13yInternal();
        $c13y->check();
        $c13y->display();
    }

    // ── menubar ───────────────────────────────────────────────────────────────

    private function menubar(): void
    {
        $tpl = TemplateRegistry::current();

        if (!PermissionService::get()->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet    = new Tabsheet();
        $tabsheet->setId('menus');
        $tabsheet->select('');
        $tabsheet->assign();

        $menu      = new BlockManager('menubar');
        $menu->loadRegisteredBlocks();
        $reg_blocks = $menu->getRegisteredBlocks();

        $mb_conf = Config::raw('blk_' . $menu->getId());
        if (is_string($mb_conf)) {
            $mb_conf = unserialize($mb_conf);
        }
        if (!is_array($mb_conf)) {
            $mb_conf = [];
        }

        foreach ($mb_conf as $id => $pos) {
            if (!isset($reg_blocks[$id])) {
                unset($mb_conf[$id]);
            }
        }
        $idx = 1;
        foreach ($reg_blocks as $id => $block) {
            if (!isset($mb_conf[$id])) {
                $mb_conf[$id] = $idx * 50;
            }
            $idx++;
        }

        if (isset($_POST['submit']) && PermissionService::get()->isWebmaster()) {
            foreach ($mb_conf as $id => $pos) {
                $hide     = isset($_POST['hide_' . $id]);
                $int_pos  = is_numeric($pos) ? (int) $pos : 0;
                $mb_conf[$id] = ($hide ? -1 : +1) * abs($int_pos);
                $raw_pos  = $_POST['pos_' . $id] ?? null;
                $pos      = is_scalar($raw_pos) ? (int) $raw_pos : 0;
                if ($pos > 0) {
                    $mb_conf[$id] = $mb_conf[$id] > 0 ? $pos : -$pos;
                }
            }
            $this->makeConsecutive($mb_conf);
            $mb_conf_db = $mb_conf;
            ServiceLocator::get(ConfigService::class)->confUpdateParam('blk_' . $menu->getId(), serialize($mb_conf_db));
            $tpl->assign(['save_success' => Lang::t('Order of menubar items has been updated successfully.')]);
        }

        $this->makeConsecutive($mb_conf);

        foreach ($mb_conf as $id => $pos) {
            $tpl->append('blocks', ['pos' => (is_numeric($pos) ? (int) $pos : 0) / 5, 'reg' => $reg_blocks[$id]]);
        }

        $action = ServiceLocator::get(UrlGenerator::class)->admin('menubar');
        $tpl->assign(['F_ACTION' => $action]);
        $tpl->assign('isWebmaster', PermissionService::get()->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Menu Management'));
        $tpl->setFilename('menubar_admin_content', 'menubar.tpl');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'menubar_admin_content');
    }

    // ── index ─────────────────────────────────────────────────────────────────

    private function index(): never
    {
        $url = '../';
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit();
    }

    // ── comments ──────────────────────────────────────────────────────────────

    private function comments(): void
    {
        $tpl = TemplateRegistry::current();

        $tpl->setFilenames(['comments' => 'comments.tpl']);
        $tpl->assign([
            'F_ACTION'          => ServiceLocator::get(UrlGenerator::class)->admin('comments'),
            'PWG_TOKEN'         => ServiceLocator::get(Util::class)->getPwgToken(),
            'COMMENTS_DISABLED' => !Config::activateComments(),
            'U_CONFIGURATION'   => ServiceLocator::get(UrlGenerator::class)->admin('configuration') . '&amp;section=comments',
            'page_data_json'    => json_encode([
                'pwg_token' => ServiceLocator::get(Util::class)->getPwgToken(),
                'str_yes_delete_confirmation' => Lang::t('Yes, delete'), 'str_no_delete_confirmation' => Lang::t('No, I have changed my mind'), 'str_delete' => Lang::t('Are you sure you want to delete comment #%s?'), 'str_deletes' => Lang::t('Are you sure you want to delete "%d" comments?'), 'str_no_comments_selected' => Lang::t('No comments selected, no actions possible.'), 'str_an_error_has' => Lang::t('An error has occured'), 'str_comment_validated' => Lang::t('The comment has been validated.'), 'str_comments_validated' => Lang::t('The comments have been validated.'), 'str_and_others' => Lang::t('and %s others'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet    = new Tabsheet();
        $tabsheet->setId('comments');
        $tabsheet->select('');
        $tabsheet->assign();

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('User comments'));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'comments');
    }

    // ── rating ────────────────────────────────────────────────────────────────

    private function rating(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        ServiceLocator::get(Util::class)->checkInputParameter('display', $_GET, false, ValidationPattern::ID);

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating');
        $tabsheet->assign();

        $start           = isset($_GET['start']) && is_numeric($_GET['start']) ? (int) $_GET['start'] : 0;
        $elements_per_page = isset($_GET['display']) && is_numeric($_GET['display']) ? (int) $_GET['display'] : 10;
        $order_by_index  = isset($_GET['order_by']) && is_numeric($_GET['order_by']) ? (int) $_GET['order_by'] : 0;

        $page['user_filter'] = '';
        if (isset($_GET['users'])) {
            if ($_GET['users'] == 'user') {
                $page['user_filter'] = ' AND r.user_id <> ' . Config::guestId();
            } elseif ($_GET['users'] == 'guest') {
                $page['user_filter'] = ' AND r.user_id = ' . Config::guestId();
            }
        }

        $page['cat_filter'] = '';
        if (isset($_GET['cat']) && is_numeric($_GET['cat'])) {
            $cat_ids = ServiceLocator::get(CategoryService::class)->getSubcatIds([(int) $_GET['cat']]);
            if (count($cat_ids) > 0) {
                $page['cat_filter'] = ' AND ic.category_id IN (' . implode(',', $cat_ids) . ')';
            }
        }

        $userFields = Config::userFields();
        $users      = [];
        foreach (ServiceLocator::get(UserRepository::class)->findAllUserIdNameMap($userFields['id'], $userFields['username'], Tables::users()) as $id => $username) {
            $users[$id] = stripslashes($username);
        }

        $query = 'SELECT COUNT(DISTINCT(r.element_id)) FROM ' . Tables::rate() . ' AS r';
        if (!empty($page['cat_filter'])) {
            $query .= ' JOIN ' . Tables::images() . ' AS i ON r.element_id = i.id JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id';
        }
        $query .= ' WHERE 1=1' . $page['user_filter'];
        $nb_images_raw = ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne();
        $nb_images     = is_numeric($nb_images_raw) ? (int) $nb_images_raw : 0;
        $nb_elements   = ServiceLocator::get(ImageRepository::class)->countRatings();

        $tpl->setFilename('rating', 'rating.tpl');
        $cache_keys  = ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['categories']);
        $rating_page_data = ['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => UrlService::getRootUrl(), 'str_create' => Lang::t('Create'), 'nb_elements' => $nb_elements];

        $tpl->assign(['navbar' => ServiceLocator::get(Util::class)->createNavigationBar(ServiceLocator::get(UrlGenerator::class)->admin() . UrlService::get()->getQueryStringDiff(['start', 'del']), $nb_images, $start, $elements_per_page), 'F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin(), 'DISPLAY' => $elements_per_page, 'NB_ELEMENTS' => $nb_elements, 'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []), 'CACHE_KEYS' => $cache_keys, 'rating_page_data_json' => json_encode($rating_page_data)]);

        $available_order_by = [[Lang::t('Rate date'), 'recently_rated DESC'], [Lang::t('Rating score'), 'score DESC'], [Lang::t('Average rate'), 'avg_rates DESC'], [Lang::t('Number of rates'), 'nb_rates DESC'], [Lang::t('Sum of rates'), 'sum_rates DESC'], [Lang::t('File name'), 'file DESC'], [Lang::t('Creation date'), 'date_creation DESC'], [Lang::t('Post date'), 'date_available DESC']];
        foreach ($available_order_by as $orderByEntry) {
            $tpl->append('order_by_options', $orderByEntry[0]);
        }
        $tpl->assign('order_by_options_selected', [$order_by_index]);

        $user_options = ['all' => Lang::t('all'), 'user' => Lang::t('Users'), 'guest' => Lang::t('Guests')];
        $tpl->assign('user_options', $user_options);
        $tpl->assign('user_options_selected', [$_GET['users'] ?? null]);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Rating'));

        $query = 'SELECT i.id, i.path, i.file, i.representative_ext, i.rating_score AS score, MAX(r.date) AS recently_rated, ROUND(AVG(r.rate),2) AS avg_rates, COUNT(r.rate) AS nb_rates, SUM(r.rate) AS sum_rates FROM ' . Tables::rate() . ' AS r LEFT JOIN ' . Tables::images() . ' AS i ON r.element_id = i.id';
        if (!empty($page['cat_filter'])) {
            $query .= ' JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id';
        }
        $query .= ' WHERE 1 = 1 ' . $page['user_filter'] . $page['cat_filter'] . ' GROUP BY i.id, i.path, i.file, i.representative_ext, i.rating_score, r.element_id ORDER BY ' . $available_order_by[$order_by_index][1] . ' LIMIT ' . $elements_per_page . ' OFFSET ' . $start . ';';

        $images = ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative();
        $tpl->assign('images', []);
        foreach ($images as $image) {
            $thumbnail_src = DerivativeImage::thumbUrl($image);
            $image_id_int  = is_numeric($image['id']) ? (int) $image['id'] : 0;
            $image_url     = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $image_id_int);
            $all_rates     = ServiceLocator::get(RateRepository::class)->findByElementId($image_id_int);
            $tpl_image     = ['id' => $image['id'], 'U_THUMB' => $thumbnail_src, 'U_URL' => $image_url, 'SCORE_RATE' => $image['score'], 'AVG_RATE' => $image['avg_rates'], 'SUM_RATE' => $image['sum_rates'], 'NB_RATES' => is_numeric($image['nb_rates']) ? (int) $image['nb_rates'] : 0, 'NB_RATES_TOTAL' => count($all_rates), 'FILE' => $image['file'], 'rates' => []];
            foreach ($all_rates as $row) {
                $user_id = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
                $user_rate = $users[$user_id] ?? '? ' . $user_id;
                $anon_id_str = is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '';
                if (strlen($anon_id_str) > 0) {
                    $user_rate .= '(' . $anon_id_str . ')';
                }
                $row['USER'] = $user_rate;
                $tpl_image['rates'][] = $row;
            }
            $tpl->append('images', $tpl_image);
        }
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'rating');
    }

    // ── rating_user ───────────────────────────────────────────────────────────

    private function ratingUser(): void
    {
        $tpl = TemplateRegistry::current();

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating_user');
        $tabsheet->assign();

        $filter_min_rates  = isset($_GET['f_min_rates']) && is_scalar($_GET['f_min_rates']) ? (int) $_GET['f_min_rates'] : 2;
        $consensus_top_number = Config::topNumber();
        if (isset($_GET['consensus_top_number']) && is_scalar($_GET['consensus_top_number'])) {
            $consensus_top_number = (int) $_GET['consensus_top_number'];
        }

        $userFields  = Config::userFields();
        $users_by_id = [];
        foreach (ServiceLocator::get(UserRepository::class)->findAllWithStatus($userFields['id'], $userFields['username'], Tables::users()) as $row) {
            $users_by_id[is_numeric($row['id']) ? (int) $row['id'] : 0] = ['name' => is_string($row['username'] ?? null) ? $row['username'] : '', 'anon' => !PermissionService::get()->isAutorizeStatus(AccessLevel::Classic, is_string($row['status'] ?? null) ? $row['status'] : '')];
        }

        $by_user_rating_model = ['rates' => []];
        foreach (Config::rateItems() as $rate) {
            $by_user_rating_model['rates'][$rate] = [];
        }

        $image_ids     = [];
        $by_user_ratings = [];
        foreach (ServiceLocator::get(RateRepository::class)->findAllOrderedByDate() as $row) {
            $user_id = is_numeric($row['user_id']) ? (int) $row['user_id'] : 0;
            if (!isset($users_by_id[$user_id])) {
                $users_by_id[$user_id] = ['name' => '???' . $user_id, 'anon' => false];
            }
            $usr = $users_by_id[$user_id];
            $user_key = $usr['anon'] ? $usr['name'] . '(' . (is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '') . ')' : $usr['name'];
            if (!isset($by_user_ratings[$user_key])) {
                $by_user_ratings[$user_key] = $by_user_rating_model;
                $by_user_ratings[$user_key]['uid']        = $user_id;
                $by_user_ratings[$user_key]['aid']        = $usr['anon'] ? $row['anonymous_id'] : '';
                $by_user_ratings[$user_key]['last_date']  = $row['date'];
                $by_user_ratings[$user_key]['first_date'] = $row['date'];
            } else {
                $by_user_ratings[$user_key]['first_date'] = $row['date'];
            }
            $rate       = is_numeric($row['rate']) ? (int) $row['rate'] : 0;
            $element_id = is_numeric($row['element_id']) ? (int) $row['element_id'] : 0;
            $by_user_ratings[$user_key]['rates'][$rate][] = ['id' => $element_id, 'date' => $row['date']];
            $image_ids[$element_id] = 1;
        }

        $image_urls = [];
        if (count($image_ids) > 0) {
            $params = ImageStdParams::getByType(DerivativeSize::Square->value);
            foreach (ServiceLocator::get(ImageRepository::class)->findByIds(array_map(intval(...), array_keys($image_ids))) as $row) {
                $id = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $image_urls[$id] = ['tn' => DerivativeImage::url($params, $row), 'page' => UrlService::get()->makePictureUrl(['image_id' => $row['id'], 'image_file' => $row['file']])];
            }
        }

        $all_img_sum = [];
        foreach (ServiceLocator::get(RateRepository::class)->findAverageByElement() as $row) {
            $all_img_sum[is_numeric($row['element_id']) ? (int) $row['element_id'] : 0] = ['avg' => is_numeric($row['avg_rate']) ? (float) $row['avg_rate'] : 0.0];
        }

        $best_rated = array_flip(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, array_column(DbConnection::get()->executeQuery('SELECT id FROM ' . Tables::images() . ' ORDER by rating_score DESC LIMIT ' . $consensus_top_number)->fetchAllAssociative(), 'id')));

        foreach ($by_user_ratings as $id => &$rating) {
            $c = $s = $ss = $consensus_dev = $consensus_dev_top = 0.0;
            $consensus_dev_top_count = 0;
            foreach ($rating['rates'] as $rate => $rates) {
                $ct = count($rates);
                $c += (float) $ct;
                $s += (float) $ct * (float) $rate;
                $ss += (float) $ct * (float) $rate * (float) $rate;
                foreach ($rates as $id_date) {
                    $dev = abs((float) $rate - (float) ($all_img_sum[$id_date['id']]['avg'] ?? 0));
                    $consensus_dev += $dev;
                    if (isset($best_rated[$id_date['id']])) {
                        $consensus_dev_top += $dev;
                        $consensus_dev_top_count++;
                    }
                }
            }
            $consensus_dev /= $c;
            if ($consensus_dev_top_count) {
                $consensus_dev_top /= (float) $consensus_dev_top_count;
            }
            $var = ($ss - $s * $s / $c) / $c;
            $rating += ['id' => $id, 'count' => (int) $c, 'avg' => $s / $c, 'cv' => $s == 0.0 ? -1 : sqrt($var) / ($s / $c), 'cd' => $consensus_dev, 'cdtop' => $consensus_dev_top_count ? $consensus_dev_top : ''];
        }
        unset($rating);

        foreach ($by_user_ratings as $id => $rating) {
            /** @var array{rates: array<int, array<int, array{id: int, date: mixed}>>, uid: int, aid: mixed, last_date: mixed, first_date: mixed, id: mixed, count: int, avg: float, cv: float|int, cd: float, cdtop: float|string} $rating */
            if ($rating['count'] <= $filter_min_rates) {
                unset($by_user_ratings[$id]);
            }
        }

        $order_by_index = isset($_GET['order_by']) && is_numeric($_GET['order_by']) ? (int) $_GET['order_by'] : 4;
        $available_order_by = [
            [Lang::t('Average rate'),        $this->avgCompare(...)],
            [Lang::t('Number of rates'),     $this->countCompare(...)],
            [Lang::t('Variation'),           $this->cvCompare(...)],
            [Lang::t('Consensus deviation'), $this->consensusDevCompare(...)],
            [Lang::t('Last'),                $this->lastRateCompare(...)],
        ];

        foreach ($available_order_by as $orderByEntry) {
            $tpl->append('order_by_options', $orderByEntry[0]);
        }
        $tpl->assign('order_by_options_selected', [$order_by_index]);

        $order_by_index_clamped = max(0, min($order_by_index, count($available_order_by) - 1));
        uasort($by_user_ratings, $available_order_by[$order_by_index_clamped][1]);

        $nb_elements = ServiceLocator::get(ImageRepository::class)->countRatings();
        $tpl->assign(['F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin(), 'F_MIN_RATES' => $filter_min_rates, 'CONSENSUS_TOP_NUMBER' => $consensus_top_number, 'available_rates' => Config::rateItems(), 'ratings' => $by_user_ratings, 'image_urls' => $image_urls, 'TN_WIDTH' => ImageStdParams::getByType(DerivativeSize::Square->value)->sizing->ideal_size[0], 'NB_ELEMENTS' => $nb_elements, 'ADMIN_PAGE_TITLE' => Lang::t('Rating'), 'page_data_json' => json_encode(['nb_elements' => $nb_elements, 'root_url' => UrlService::getRootUrl(), 'str_delete_ratings_confirm' => Lang::t('Are you sure you want to delete the ratings of the user "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)]);
        $tpl->setFilename('rating', 'rating_user.tpl');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'rating');
    }

    // ── profile ───────────────────────────────────────────────────────────────

    private function profile(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        ServiceLocator::get(Util::class)->checkInputParameter('user_id', $_GET, false, ValidationPattern::ID);

        $userIdRaw = $_GET['user_id'] ?? null;
        $editUserId = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;
        $edit_user  = UserService::get()->buildUser($editUserId, false);

        if (!empty($_POST)) {
            ServiceLocator::get(Util::class)->checkPwgToken();
        }

        $errors = [];
        ServiceLocator::get(ProfileService::class)->saveProfileFromPost($edit_user, $errors);

        ServiceLocator::get(ProfileService::class)->loadProfileInTemplate(
            ServiceLocator::get(UrlGenerator::class)->admin('profile') . '&amp;user_id=' . (is_scalar($edit_user['id'] ?? null) ? (string) $edit_user['id'] : ''),
            ServiceLocator::get(UrlGenerator::class)->admin('user_list'),
            $edit_user
        );

        $pageErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
        $page['errors'] = array_merge($pageErrors, $errors);

        $tpl->setFilename('profile', 'profile.tpl');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'profile');
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /** @param string[] $check_key_treated */
    private function doTimeoutTreatment(string $post_keyname, array $check_key_treated = []): bool
    {
        $ctx = MailNotificationContext::current();
        if ($ctx->isSendmailTimeout) {
            if (isset($_POST[$post_keyname])) {
                $rawPostKeyname   = $_POST[$post_keyname] ?? null;
                $post_keyname_val = is_array($rawPostKeyname) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawPostKeyname) : [];
                $post_count       = count($post_keyname_val);
                $treated_count    = count($check_key_treated);
                $time_refresh     = $treated_count !== 0 ? (int) ceil((StringUtil::get()->getMoment() - $ctx->startTime) * (float) $post_count / (float) $treated_count) : 0;
                $_POST[$post_keyname] = array_diff($post_keyname_val, $check_key_treated);
                $this->mustRepost = true;
                PageState::current()->addError(Translator::get()->plural('Execution time is out, treatment must be continue [Estimated time: %d second].', 'Execution time is out, treatment must be continue [Estimated time: %d seconds].', $time_refresh));
                return true;
            }
        }
        return false;
    }

    private function getTabStatus(string $mode): int
    {
        return match ($mode) {
            'param', 'subscribe' => AccessLevel::Webmaster,
            'send' => AccessLevel::Administrator,
            default => AccessLevel::Webmaster,
        };
    }

    private function insertNewDataUserMailNotification(string $base_url): void
    {
        $ctx       = MailNotificationContext::current();
        $notifRepo = ServiceLocator::get(NotificationRepository::class);
        $userFields = Config::userFields();

        $notifRepo->clearEmptyEmails($userFields['email'], Tables::users());
        $users_without_notif = $notifRepo->findUsersWithoutNotification($userFields['id'], $userFields['username'], $userFields['email'], Tables::users());

        if (count($users_without_notif) > 0) {
            $inserts        = [];
            $check_key_list = [];
            foreach ($users_without_notif as $nbm_user) {
                $nbm_user['check_key'] = ServiceLocator::get(NotificationAdminService::class)->findAvailableCheckKey();
                $check_key_list[]      = $nbm_user['check_key'];
                $inserts[]             = ['user_id' => $nbm_user['user_id'], 'check_key' => $nbm_user['check_key'], 'enabled' => 'false'];
                $mailAddressRaw = $nbm_user['mail_address'] ?? null;
                $usernameRaw    = $nbm_user['username'] ?? null;
                PageState::current()->addInfo(Lang::t('User %s [%s] added.', stripslashes(is_string($usernameRaw) ? $usernameRaw : ''), is_string($mailAddressRaw) ? $mailAddressRaw : ''));
            }
            Dml::massInserts(Tables::userMailNotification(), ['user_id', 'check_key', 'enabled'], $inserts);
            $check_key_treated = ServiceLocator::get(NotificationAdminService::class)->doSubscribeUnsubscribeNotificationByMail(true, Config::nbmDefaultValueUserEnabled(), $check_key_list);

            if ($ctx->isSendmailTimeout) {
                $untreated_keys = array_diff($check_key_list, $check_key_treated);
                if (count($untreated_keys) != 0) {
                    ServiceLocator::get(NotificationRepository::class)->deleteByCheckKeys(array_values($untreated_keys));
                    Util::get()->redirect($base_url . UrlService::get()->getQueryStringDiff([], false), Lang::t('Operation in progress') . "\n" . Lang::t('Please wait...'));
                }
            }
        }
    }

    /** @param string|array<mixed> $customize_mail_content */
    public function renderGlobalCustomizeMailContent(string|array $customize_mail_content): string
    {
        if (is_array($customize_mail_content)) {
            return '';
        }
        if (Config::nbmSendHtmlMail() && !str_starts_with($customize_mail_content, '<')) {
            return nl2br(htmlspecialchars($customize_mail_content));
        }
        return $customize_mail_content;
    }

    /**
     * @param string[] $check_key_list
     * @return array<mixed>
     */
    private function doActionSendMailNotification(string $action = 'list_to_send', array $check_key_list = [], string $customize_mail_content = ''): array
    {
        $ctx         = MailNotificationContext::current();
        $return_list = [];

        if (in_array($action, ['list_to_send', 'send'])) {
            $dbnow         = new \DateTimeImmutable()->format('Y-m-d H:i:s');
            $is_action_send = ($action == 'send');
            $data_users    = ServiceLocator::get(NotificationAdminService::class)->getUserNotifications('send', $check_key_list);
            $is_list_all_without_test = ($ctx->isSendmailTimeout || Config::nbmListAllEnabledUsersToSend());

            if (!$is_list_all_without_test || $is_action_send) {
                if (count($data_users) > 0) {
                    $datas = [];
                    if (empty($customize_mail_content)) {
                        $customize_mail_content = Config::nbmComplementaryMailContent();
                    }
                    $customize_mail_content = EventDispatcher::dispatch('nbm_render_global_customize_mail_content', $customize_mail_content);
                    $msg_break_timeout = $is_action_send ? Lang::t('Time to send mail is limited. Others mails are skipped.') : Lang::t('Prepared time for list of users to send mail is limited. Others users are not listed.');

                    ServiceLocator::get(NotificationAdminService::class)->beginUsersEnvNbm($is_action_send);
                    foreach ($data_users as $nbm_user) {
                        if (!$is_action_send && ServiceLocator::get(NotificationAdminService::class)->checkSendmailTimeout()) {
                            PageState::current()->addInfo($msg_break_timeout);
                            break;
                        }
                        if ($is_action_send && ServiceLocator::get(NotificationAdminService::class)->checkSendmailTimeout()) {
                            PageState::current()->addError($msg_break_timeout);
                            break;
                        }

                        ServiceLocator::get(NotificationAdminService::class)->setUserOnEnvNbm($nbm_user, $is_action_send);

                        if ($is_action_send) {
                            $auth = null;
                            $url_params = [];
                            $auth_key = AuthService::get()->createUserAuthKey(is_numeric($nbm_user['user_id']) ? (int) $nbm_user['user_id'] : 0, is_string($nbm_user['status']) ? $nbm_user['status'] : null);
                            if (is_array($auth_key) && is_string($auth_key['auth_key'] ?? null)) {
                                $auth = $auth_key['auth_key'];
                                $url_params['auth'] = $auth;
                            }

                            UrlService::get()->setMakeFullUrl();
                            $return_list[] = (string) $nbm_user['check_key'];
                            $last_send     = is_string($nbm_user['last_send']) || is_null($nbm_user['last_send']) ? $nbm_user['last_send'] : (string) $nbm_user['last_send'];

                            $news = [];
                            if (Config::nbmSendDetailedContent()) {
                                $news = ServiceLocator::get(NotificationService::class)->news($last_send, $dbnow, false, Config::nbmSendHtmlMail(), $auth);
                                $exist_data = count($news) > 0;
                            } else {
                                $exist_data = ServiceLocator::get(NotificationService::class)->newsExists($last_send, $dbnow);
                            }

                            if ($exist_data) {
                                $subject = '[' . Config::galleryTitle() . '] ' . Lang::t('New photos added');
                                ServiceLocator::get(NotificationAdminService::class)->assignVarsNbmMailContent($nbm_user);
                                $nbmTpl = $ctx->mailTemplate ?? throw new \LogicException('mail_template not set');

                                if (!is_null($nbm_user['last_send'])) {
                                    $nbmTpl->assign('content_new_elements_between', ['DATE_BETWEEN_1' => $nbm_user['last_send'], 'DATE_BETWEEN_2' => $dbnow]);
                                } else {
                                    $nbmTpl->assign('content_new_elements_single', ['DATE_SINGLE' => $dbnow]);
                                }

                                if (Config::nbmSendDetailedContent()) {
                                    $nbmTpl->assign('global_new_lines', $news);
                                }

                                $nbm_user_customize_mail_content = EventDispatcher::dispatch('nbm_render_user_customize_mail_content', $customize_mail_content, $nbm_user);
                                if (!empty($nbm_user_customize_mail_content)) {
                                    $nbmTpl->assign('custom_mail_content', $nbm_user_customize_mail_content);
                                }

                                if (Config::nbmSendHtmlMail() && Config::nbmSendRecentPostDates()) {
                                    $recent_post_dates = ServiceLocator::get(NotificationService::class)->getRecentPostDatesArray(Config::recentPostDates()['NBM']);
                                    foreach ($recent_post_dates as $date_detail) {
                                        $date_detail_arr = is_array($date_detail) ? $date_detail : [];
                                        $nbmTpl->append('recent_posts', ['TITLE' => ServiceLocator::get(NotificationService::class)->getTitleRecentPostDate($date_detail_arr), 'HTML_DATA' => ServiceLocator::get(NotificationService::class)->getHtmlDescriptionRecentPostDate($date_detail_arr, is_string($auth) ? $auth : null)]);
                                    }
                                }

                                $nbmTpl->assign(['GOTO_GALLERY_TITLE' => Config::galleryTitle(), 'GOTO_GALLERY_URL' => UrlService::get()->addUrlParams(UrlService::get()->getGalleryHomeUrl(), $url_params), 'SEND_AS_NAME' => $ctx->sendAsName]);

                                $nbmUsernameRaw    = $nbm_user['username']     ?? null;
                                $nbmMailAddressRaw = $nbm_user['mail_address'] ?? null;
                                $ret = ServiceLocator::get(MailService::class)->pwgMail(['name' => stripslashes(is_string($nbmUsernameRaw) ? $nbmUsernameRaw : ''), 'email' => is_string($nbmMailAddressRaw) ? $nbmMailAddressRaw : ''], ['from' => $ctx->sendAsMailFormated, 'subject' => $subject, 'email_format' => $ctx->emailFormat, 'content' => $nbmTpl->parse('notification_by_mail', true), 'content_format' => $ctx->emailFormat, 'auth_key' => $auth]);

                                if ($ret) {
                                    ServiceLocator::get(NotificationAdminService::class)->incMailSentSuccess($nbm_user);
                                    $datas[] = ['user_id' => $nbm_user['user_id'], 'last_send' => $dbnow];
                                } else {
                                    ServiceLocator::get(NotificationAdminService::class)->incMailSentFailed($nbm_user);
                                }
                                UrlService::get()->unsetMakeFullUrl();
                            }
                        } else {
                            $last_send = isset($nbm_user['last_send']) ? (string) $nbm_user['last_send'] : null;
                            if (ServiceLocator::get(NotificationService::class)->newsExists($last_send, $dbnow)) {
                                $return_list[] = $nbm_user;
                            }
                        }
                        ServiceLocator::get(NotificationAdminService::class)->unsetUserOnEnvNbm();
                    }
                    ServiceLocator::get(NotificationAdminService::class)->endUsersEnvNbm();

                    if ($is_action_send) {
                        Dml::massUpdates(Tables::userMailNotification(), ['primary' => ['user_id'], 'update' => ['last_send']], $datas);
                        ServiceLocator::get(NotificationAdminService::class)->displayCounterInfo();
                    }
                } else {
                    if ($is_action_send) {
                        PageState::current()->addError(Lang::t('No user to send notifications by mail.'));
                    }
                }
            } else {
                $return_list = $data_users;
            }
        }
        return $return_list;
    }

    /**
     * @param string[] $sortable_by
     * @param string[]|null $get_rejects
     * @return array<mixed>
     */
    private function parseSortVariables(array $sortable_by, ?string $default_field, string $get_param, ?array $get_rejects, ?string $template_var, string $anchor = ''): array
    {
        $tpl             = TemplateRegistry::current();
        /** @var string $rawRequestUri */
        $rawRequestUri   = $_SERVER['REQUEST_URI'] ?? '';
        $url_components  = parse_url($rawRequestUri);
        if ($url_components === false) {
            $url_components = ['path' => '', 'query' => ''];
        }
        $base_url = $url_components['path'] ?? '';
        $query    = $url_components['query'] ?? '';

        // Piwigo's question_mark_in_urls mode: QUERY_STRING starts with '/' (e.g. '/admin&page=...')
        // PHP's parse_str converts '/' to '_' in key names, producing '_admin' which is not
        // a real GET param. Strip the routing path prefix and append it verbatim to base_url.
        if (str_starts_with($query, '/')) {
            $amp_pos  = strpos($query, '&');
            $route    = $amp_pos !== false ? substr($query, 0, $amp_pos) : $query;
            $query    = $amp_pos !== false ? substr($query, $amp_pos + 1) : '';
            $base_url .= '?' . $route;
        }

        parse_str($query, $vars);
        $is_first = $base_url === ($url_components['path'] ?? '');
        foreach ($vars as $key => $value) {
            if (!in_array($key, $get_rejects ?? []) && $key != $get_param) {
                $base_url .= $is_first ? '?' : '&amp;';
                $is_first  = false;
                if (!in_array($key, ['page', 'psf', 'dpsf', 'pwg_token'])) {
                    HtmlService::fatalError('unexpected URL get key');
                }
                $base_url .= urlencode((string) $key) . '=' . urlencode(is_string($value) ? $value : '');
            }
        }
        $ret = [];
        foreach ($sortable_by as $field) {
            $url  = $base_url;
            $disp = '↓';
            if ($field !== ($_GET[$get_param] ?? null)) {
                if ($default_field != $field) {
                    $url = UrlService::get()->addUrlParams($url, [$get_param => $field]);
                } elseif (!isset($_GET[$get_param])) {
                    $ret[] = $field;
                    $disp = '<em>' . $disp . '</em>';
                }
            } else {
                $ret[] = $field;
                $disp = '<em>' . $disp . '</em>';
            }
            if (isset($template_var)) {
                $tpl->assign($template_var . strtoupper($field), '<a href="' . $url . $anchor . '" title="' . Lang::t('Sort order') . '">' . $disp . '</a>');
            }
        }
        return $ret;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function avgCompare(array $a, array $b): int
    {
        $d = (is_numeric($a['avg']) ? (float) $a['avg'] : 0.0) - (is_numeric($b['avg']) ? (float) $b['avg'] : 0.0);
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function countCompare(array $a, array $b): int
    {
        $d = (is_numeric($a['count']) ? (int) $a['count'] : 0) - (is_numeric($b['count']) ? (int) $b['count'] : 0);
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function cvCompare(array $a, array $b): int
    {
        $d = (is_numeric($b['cv']) ? (float) $b['cv'] : 0.0) - (is_numeric($a['cv']) ? (float) $a['cv'] : 0.0);
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function consensusDevCompare(array $a, array $b): int
    {
        $d = (is_numeric($b['cd']) ? (float) $b['cd'] : 0.0) - (is_numeric($a['cd']) ? (float) $a['cd'] : 0.0);
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function lastRateCompare(array $a, array $b): int
    {
        $da = is_scalar($a['last_date'] ?? null) ? (string) $a['last_date'] : '';
        $db = is_scalar($b['last_date'] ?? null) ? (string) $b['last_date'] : '';
        return -strcmp($da, $db);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function cmpDay(array $a, array $b): int
    {
        return $a['x'] <=> $b['x'];
    }

    private function absFnCmp(mixed $a, mixed $b): int
    {
        return abs(is_numeric($a) ? (int) $a : 0) - abs(is_numeric($b) ? (int) $b : 0);
    }

    /** @param array<mixed> $orders */
    private function makeConsecutive(array &$orders, int $step = 50): void
    {
        uasort($orders, $this->absFnCmp(...));
        $crt = 1;
        foreach ($orders as $id => $pos) {
            $orders[$id] = $step * ($pos < 0 ? -$crt : $crt);
            $crt++;
        }
    }
}
