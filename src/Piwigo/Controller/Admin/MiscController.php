<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Detection\MobileDetect;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomaliesRepository;
use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Event\Admin\GetPopupHelpContent;
use Piwigo\Event\Lifecycle\NbmEventHandlerAdded;
use Piwigo\Event\Location\LocEndHelp;
use Piwigo\Event\Location\LocEndIntro;
use Piwigo\Event\Mail\NbmRenderGlobalCustomizeMailContent;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Exception\AuthException;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageFormatRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\MenubarLayoutRepository;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Page\PaginationService;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Rate\RateRepository;
use Piwigo\Session\Session;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class MiscController implements AdminSubControllerInterface
{
    /** @var list<string> */
    public const array PAGES = [
        'notification_by_mail', 'permalinks', 'tags', 'help', 'popuphelp',
        'intro', 'menubar', 'index', 'comments', 'rating', 'rating_user', 'profile',
    ];

    private bool $mustRepost = false;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly AdminService $adminService,
        private readonly AlbumsTabRenderer $albumsTabRenderer,
        private readonly AuthService $authService,
        private readonly CategoryRepository $categoryRepository,
        private readonly CategoryService $categoryService,
        private readonly CommentRepository $commentRepository,
        private readonly ConfigRepository $configRepository,
        private readonly ConfigService $configService,
        private readonly DateService $dateService,
        private readonly HtmlService $htmlService,
        private readonly ImageAdminService $imageAdminService,
        private readonly ImageFormatRepository $imageFormatRepository,
        private readonly ImageRepository $imageRepository,
        private readonly LangService $langService,
        private readonly MenubarLayoutRepository $menubarLayout,
        private readonly NotificationAdminService $notificationAdminService,
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
        private readonly PermalinkRepository $permalinkRepository,
        private readonly PermalinkService $permalinkService,
        private readonly PermissionService $permissionService,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PreferencesService $preferencesService,
        private readonly ProfileService $profileService,
        private readonly RateRepository $rateRepository,
        private readonly Session $session,
        private readonly TagAdminService $tagAdminService,
        private readonly TagRepository $tagRepository,
        private readonly UrlGenerator $urlGenerator,
        private readonly UrlService $urlService,
        private readonly UserRepository $userRepository,
        private readonly UserService $userService,
        private readonly CsrfService $csrfService,
        private readonly InputValidator $inputValidator,
        private readonly RedirectResponder $redirectResponder,
        private readonly PaginationService $paginationService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CacheItemPoolInterface $pool,
        private readonly IntegrityIgnoredAnomaliesRepository $integrityIgnoredAnomaliesRepo,
    ) {
    }

    #[\Override]
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

        MailNotificationContext::init();

        $this->inputValidator->check('mode', $_GET, false, '/^(param|subscribe|send)$/');

        $base_url = $this->urlGenerator->admin();
        $this->mustRepost = false;

        $mode = (!isset($_GET['mode']) || !is_string($_GET['mode'])) ? 'send' : $_GET['mode'];

        $this->permissionService->checkStatus($this->getTabStatus($mode));

        // nbm_render_global_customize_mail_content listener now registers
        // at boot via NbmRenderGlobalCustomizeMailContentSubscriber.
        $this->dispatcher->dispatch(new NbmEventHandlerAdded());

        if (count($_POST) == 0) {
            $this->insertNewDataUserMailNotification($base_url);
        }

        if (!empty($_POST)) {
            $this->csrfService->check();
        }

        switch ($mode) {
            case 'param':
                if (isset($_POST['param_submit'])) {
                    $nbmSendMailAsRaw = $_POST['nbm_send_mail_as'] ?? null;
                    $_POST['nbm_send_mail_as'] = strip_tags(is_string($nbmSendMailAsRaw) ? $nbmSendMailAsRaw : '');
                    $this->inputValidator->check('nbm_send_html_mail', $_POST, false, '/^(true|false)$/');
                    $this->inputValidator->check('nbm_send_detailed_content', $_POST, false, '/^(true|false)$/');
                    $this->inputValidator->check('nbm_send_recent_post_dates', $_POST, false, '/^(true|false)$/');
                    $updated_param_count = 0;
                    foreach ($this->configRepository->findByParamPattern('nbm\\_%') as $nbm_user) {
                        $param = $nbm_user['param'];
                        if (!isset($_POST[$param])) {
                            continue;
                        }
                        /** @var string $rawParamVal */
                        $rawParamVal = $_POST[$param];
                        $this->configService->confUpdateParam($param, $rawParamVal, true);
                        $updated_param_count++;
                    }
                    $tpl->assign(['save_success' => Translator::get()->plural('%d parameter was updated.', '%d parameters were updated.', $updated_param_count)]);
                }
                // fall through
                // no break
            case 'subscribe':
                if (isset($_POST['falsify']) && isset($_POST['cat_true'])) {
                    $rawCatTrue2 = $_POST['cat_true'];
                    $cat_true = is_array($rawCatTrue2) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatTrue2) : [];
                    $check_key_treated = $this->notificationAdminService->unsubscribeNotificationByMail(true, $cat_true);
                    if ($this->doTimeoutTreatment('cat_true', $check_key_treated)) {
                        $this->mustRepost = true;
                    }
                } elseif (isset($_POST['trueify']) && isset($_POST['cat_false'])) {
                    $rawCatFalse2 = $_POST['cat_false'];
                    $cat_false = is_array($rawCatFalse2) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawCatFalse2) : [];
                    $check_key_treated = $this->notificationAdminService->subscribeNotificationByMail(true, $cat_false);
                    if ($this->doTimeoutTreatment('cat_false', $check_key_treated)) {
                        $this->mustRepost = true;
                    }
                }
                break;
            case 'send':
                if (!isset($_POST['send_submit']) || !isset($_POST['send_selection']) || !isset($_POST['send_customize_mail_content'])) {
                    break;
                }
                $rawSendSelection = $_POST['send_selection'];
                $send_selection = is_array($rawSendSelection) ? array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawSendSelection) : [];
                $rawCustomMail = $_POST['send_customize_mail_content'];
                $check_key_treated = $this->doActionSendMailNotification('send', $send_selection, stripslashes(is_string($rawCustomMail) ? $rawCustomMail : ''));
                $check_key_treated_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $check_key_treated);
                if ($this->doTimeoutTreatment('send_selection', $check_key_treated_str)) {
                    $this->mustRepost = true;
                }
                break;
        }

        $tpl->assign(['PWG_TOKEN' => $this->csrfService->getToken(), 'U_HELP' => $this->urlGenerator->adminPopupHelp('notification_by_mail'), 'F_ACTION' => $base_url . $this->urlService->getQueryStringDiff([])]);

        if ($this->permissionService->isAutorizeStatus(AccessLevel::Webmaster)) {
            $tabsheet = new Tabsheet();
            $tabsheet->setId('nbm');
            $tabsheet->select($mode);
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

        switch ($mode) {
            case 'param':
                $tpl->assign($mode, ['SEND_HTML_MAIL' => Config::nbmSendHtmlMail(), 'SEND_MAIL_AS' => Config::nbmSendMailAs(), 'SEND_DETAILED_CONTENT' => Config::nbmSendDetailedContent(), 'COMPLEMENTARY_MAIL_CONTENT' => Config::nbmComplementaryMailContent(), 'SEND_RECENT_POST_DATES' => Config::nbmSendRecentPostDates()]);
                break;
            case 'subscribe':
                $tpl->assign($mode, true);
                $tpl->assign(['L_CAT_OPTIONS_TRUE' => Lang::t('Subscribed'), 'L_CAT_OPTIONS_FALSE' => Lang::t('Unsubscribed')]);
                $data_users = $this->notificationAdminService->getUserNotifications('subscribe');
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
                $tpl->assignVarFromTemplate('DOUBLE_SELECT', 'double_select.latte');
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
                $tpl->assign($mode, $tpl_var);
                if (Config::authKeyDuration() > 0) {
                    $strMiscResult = strtotime('now -' . Config::authKeyDuration() . ' second');
                    $tpl->assign('auth_key_duration', $this->dateService->timeSince($strMiscResult !== false ? $strMiscResult : null, 'second', null, false));
                }
                break;
        }

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Send mail to users'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'notification_by_mail.latte');
    }

    // ── permalinks ────────────────────────────────────────────────────────────

    private function permalinks(): void
    {
        $tpl = TemplateRegistry::current();

        $this->inputValidator->check('cat_id', $_POST, false, ValidationPattern::ID);

        $selected_cat = [];
        if (isset($_POST['set_permalink']) && $_POST['cat_id'] > 0) {
            $this->csrfService->check();
            $permalinkRaw = $_POST['permalink'] ?? null;
            $permalink  = is_string($permalinkRaw) ? $permalinkRaw : '';
            $rawPostCatId = $_POST['cat_id'];
            $postCatId  = is_string($rawPostCatId) ? $rawPostCatId : '';
            if (empty($permalink)) {
                $this->permalinkService->deleteCatPermalink($postCatId, isset($_POST['save']));
            } else {
                $this->permalinkService->setCatPermalink($postCatId, $permalink, isset($_POST['save']));
            }
            $selected_cat = [(int) $postCatId];
        } elseif (isset($_GET['delete_permanent'])) {
            $this->csrfService->check();
            $rawDeletePermanent = $_GET['delete_permanent'];
            $deleted = $this->permalinkRepository->deleteOldPermalinkByValue(is_string($rawDeletePermanent) ? $rawDeletePermanent : '');
            if (!$deleted) {
                PageState::current()->addError(Lang::t('Cannot delete the old permalink !'));
            }
        }

        $this->albumsTabRenderer->render('permalinks');

        $query = 'SELECT id, permalink, CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name, uppercats, global_rank FROM ' . Tables::categories();
        $this->categoryService->displaySelectCatWrapper($query, $selected_cat, 'categories', false);

        $pwg_token = $this->csrfService->getToken();

        $sort_by = $this->parseSortVariables(['id', 'name', 'permalink'], 'name', 'psf', ['delete_permanent'], 'SORT_');
        $sortBy0  = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
        $categories = [];
        foreach ($this->categoryRepository->findCategoriesWithPermalink($sortBy0) as $row) {
            $categories[] = [
                'id'          => $row->id,
                'permalink'   => $row->permalink,
                'uppercats'   => $row->uppercats,
                'global_rank' => $row->globalRank,
                'name'        => $this->htmlService->getCatDisplayNameCache($row->uppercats),
            ];
        }
        if ($sort_by[0] == 'name') {
            usort($categories, $this->categoryService->globalRankCompare(...));
        }
        $tpl->assign('permalinks', $categories);

        $sort_by            = $this->parseSortVariables(['cat_id', 'permalink', 'date_deleted', 'last_hit', 'hit'], null, 'dpsf', ['delete_permanent'], 'SORT_OLD_', '#old_permalinks');
        $url_del_base       = $this->urlGenerator->admin('permalinks');
        $sortByOld0         = is_scalar($sort_by[0] ?? null) ? (string) $sort_by[0] : '';
        $deleted_permalinks = [];
        foreach ($this->categoryRepository->findOldPermalinks($sortByOld0) as $row) {
            $deleted_permalinks[] = [
                'cat_id'       => $row->catId,
                'permalink'    => $row->permalink,
                'date_deleted' => $row->dateDeleted,
                'last_hit'     => $row->lastHit,
                'hit'          => $row->hit,
                'name'         => $this->htmlService->getCatDisplayNameCache((string) $row->catId),
                'U_DELETE'     => $this->urlService->addUrlParams($url_del_base, ['delete_permanent' => $row->permalink, 'pwg_token' => $pwg_token]),
            ];
        }

        $tpl->assign(['PWG_TOKEN' => $pwg_token, 'U_HELP' => $this->urlGenerator->adminPopupHelp('permalinks'), 'deleted_permalinks' => $deleted_permalinks, 'ADMIN_PAGE_TITLE' => Lang::t('Albums'), 'page_data_json' => json_encode(['nb_cats' => count($categories)], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)]);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'permalinks.latte');
    }

    // ── tags ──────────────────────────────────────────────────────────────────

    private function tags(): void
    {
        $tpl = TemplateRegistry::current();

        $tabsheet    = new Tabsheet();
        $tabsheet->setId('tags');
        $tabsheet->select('');
        $tabsheet->assign();

        if (isset($_GET['action']) && 'delete_orphans' == $_GET['action']) {
            $this->csrfService->check();
            $this->tagAdminService->deleteOrphanTags();
            $this->session->messageTags = Lang::t('Orphan tags deleted');
            $this->redirectResponder->redirect($this->urlGenerator->admin('tags'));
        }

        $tpl->assign(['F_ACTION' => $this->urlGenerator->admin('tags'), 'PWG_TOKEN' => $this->csrfService->getToken(), 'BATCH_MANAGER_URL' => $this->urlGenerator->admin('batch_manager')]);

        $warning_tags     = '';
        $orphan_tags      = $this->tagAdminService->getOrphanTags();
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tag_name       = is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '';
            $orphanRenderEvent = new RenderTagName($tag_name, $tag);
            $this->dispatcher->dispatch($orphanRenderEvent);
            $orphan_tag_names[] = $orphanRenderEvent->tagName;
        }

        $orphan_tag_names_array = '[]';
        if (count($orphan_tag_names) > 0) {
            $warning_tags = new Html(sprintf(Lang::t('You have %d orphan tags %s'), count($orphan_tag_names), '<a class="icon-eye" data-url="' . $this->urlGenerator->admin('tags') . '&amp;action=delete_orphans&amp;pwg_token=' . $this->csrfService->getToken() . '">' . htmlspecialchars(Lang::t('Review')) . '</a>'));
            $orphan_tag_names_array = '["' . implode('" ,"', array_map(htmlentities(...), $orphan_tag_names, array_fill(0, count($orphan_tag_names), ENT_QUOTES))) . '"]';
        }
        $tpl->assign(['orphan_tag_names_array' => $orphan_tag_names_array, 'warning_tags' => $warning_tags]);

        $message_tags = $this->session->messageTags ?? '';
        $this->session->messageTags = null;   // one-shot flash: consume and clear.
        $tpl->assign('message_tags', $message_tags);

        $per_page   = 100;
        $_tagRepo   = $this->tagRepository;
        $tag_counters = $_tagRepo->getTagCounters();
        $all_tags   = [];
        foreach ($_tagRepo->findAll() as $tagEntity) {
            $tag             = $tagEntity->toRow();
            $rawNameStr      = $tagEntity->name;
            $tag['raw_name'] = $rawNameStr;
            $renderEvent     = new RenderTagName($rawNameStr, $tag);
            $this->dispatcher->dispatch($renderEvent);
            $tag['name']     = $renderEvent->tagName;
            $tag_id_key      = (string) $tagEntity->id->value;
            $counter         = is_numeric($tag_counters[$tag_id_key] ?? null) ? (int) $tag_counters[$tag_id_key] : 0;
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }
            $altEvent       = new GetTagAltNames([], $rawNameStr);
            $this->dispatcher->dispatch($altEvent);
            $alt_names      = array_diff(array_unique(array_filter($altEvent->value, is_string(...))), [$tag['name']]);
            if (count($alt_names)) {
                $tag['alt_names'] = implode(', ', $alt_names);
            }
            $all_tags[] = $tag;
        }
        usort($all_tags, $this->htmlService->tagAlphaCompare(...));

        $tpl->assign(['first_tags' => array_slice($all_tags, 0, $per_page), 'data' => $all_tags, 'total' => count($all_tags), 'per_page' => $per_page, 'ADMIN_PAGE_TITLE' => Lang::t('Tags')]);
        $tpl->assign('page_data_json', json_encode([
            'pwg_token' => $this->csrfService->getToken(), 'total' => count($all_tags), 'orphan_tag_names' => $orphan_tag_names,
            'str_already_exist' => Lang::t('Tag "%s" already exists'), 'str_and_others_tags' => Lang::t('and %s others'), 'str_clear_selection' => Lang::t('Clear Selection'), 'str_copy' => Lang::t(' (copy)'), 'str_delete' => Lang::t('Delete tag "%s"?'), 'str_delete_orphan_tags' => Lang::t('Delete orphan tags ?'), 'str_delete_tags' => Lang::t('Delete tags {%s}?'), 'str_delete_them' => Lang::t('Delete them'), 'str_keep_them' => Lang::t('Keep them'), 'str_merged_into' => Lang::t('Tag(s) {%s1} succesfully merged into "%s2"'), 'str_no_delete_confirmation' => Lang::t('No, I have changed my mind'), 'str_no_photos' => Lang::t('no photo'), 'str_number_photos' => Lang::t('%d photos'), 'str_orphan_tags' => Lang::t('You have %s1 orphan : %s2'), 'str_other_copy' => Lang::t(' (copy %s)'), 'str_select_all_tag' => Lang::t('Select all %d tags'), 'str_selection_done' => Lang::t('The %d tags on this page are selected'), 'str_tag_created' => Lang::t('Tag "%s" created'), 'str_tag_deleted' => Lang::t('Tag "%s" succesfully deleted'), 'str_tag_found' => Lang::t('<b>%d</b> tag found'), 'str_tag_rename' => Lang::t('Rename "%s"'), 'str_tag_selected' => Lang::t('<b>%d</b> tag selected'), 'str_tags_deleted' => Lang::t('Tags {%s} succesfully deleted'), 'str_tags_found' => Lang::t('<b>%d</b> tags found'), 'str_yes_delete_confirmation' => Lang::t('Yes, delete'), 'str_yes_rename_confirmation' => Lang::t('Yes, rename'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'tags.latte');
    }

    // ── help ──────────────────────────────────────────────────────────────────

    private function help(): void
    {
        $tpl = TemplateRegistry::current();

        $selected = isset($_GET['section']) && is_string($_GET['section']) ? $_GET['section'] : 'add_photos';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('help');
        $tabsheet->select($selected);
        $tabsheet->assign();

        $this->dispatcher->dispatch(new LocEndHelp());

        $helpContent = $this->langService->loadLanguage('help/help_' . $tabsheet->selected . '.html', '', ['return' => true]);
        $tpl->assign([
            'HELP_CONTENT'       => new Html(is_string($helpContent) ? $helpContent : ''),
            'HELP_SECTION_TITLE' => $tabsheet->sheets[$tabsheet->selected]['caption'] ?? '',
        ]);

        $language_prefix = substr(CurrentUser::get()->language, 0, 3);
        if ('en_' == $language_prefix) {
            PageState::current()->addMessage(new Html(sprintf('Need help to use Piwigo? <a href="%s" target="_blank">Check the online documentation</a> !', 'https://doc.piwigo.org/')));
        } elseif ('fr_' == $language_prefix) {
            PageState::current()->addMessage(new Html(sprintf('Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !', 'https://doc-fr.piwigo.org/')));
        }

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'help.latte');
    }

    // ── popuphelp ─────────────────────────────────────────────────────────────

    private function popupHelp(): void
    {
        $tpl = TemplateRegistry::current();

        if (!isset($_GET['output']) || 'content_only' != $_GET['output']) {
            $title = Lang::t('Piwigo Help');
            $ps = PageState::current();
            $ps->bodyId     = 'thePopuphelpPage';
            $ps->pageBanner = '<h1>' . $title . '</h1>';
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
            $tpl->assign(['U_RETURN' => '', 'USERNAME' => '', 'U_FAQ' => '', 'U_CHANGE_THEME' => '', 'U_LOGOUT' => '']);
            PageHeaderRenderer::render($title);
        }

        $rawHelpPage = $_GET['help'] ?? null;
        $helpPage = is_string($rawHelpPage) ? $rawHelpPage : '';
        if (isset($_GET['help']) && preg_match('/^[a-z_]*$/', $helpPage)) {
            $loaded = $this->langService->loadLanguage('help/' . $helpPage . '.html', '', ['force_fallback' => 'en_UK', 'return' => true]);
            $help_content = is_string($loaded) ? $loaded : '';
            $rawHelp = is_string($_GET['help']) ? $_GET['help'] : '';
            $helpEvent = new GetPopupHelpContent($help_content, $rawHelp);
            $this->dispatcher->dispatch($helpEvent);
            $help_content = $helpEvent->helpContent;
        } else {
            throw new AuthException('Hacking attempt!');
        }

        $tpl->assign(['HELP_CONTENT' => new Html($help_content)]);

        if (isset($_GET['output']) && 'content_only' == $_GET['output']) {
            echo $help_content;
            exit();
        }

        $tpl->pparse('popuphelp.latte');
        PageTailRenderer::render();
    }

    // ── intro ─────────────────────────────────────────────────────────────────

    private function intro(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $activePluginIds = $this->pluginRegistry->getActiveIds();

        if (isset($_GET['action']) && 'hide_newsletter_subscription' == $_GET['action']) {
            $this->preferencesService->userprefsUpdateParam('show_newsletter_subscription', 'false');
            exit();
        }

        $tabsheet    = new Tabsheet();
        $tabsheet->setId('admin_home');
        $tabsheet->select('');
        $tabsheet->assign();

        if (Config::activateComments()) {
            $nbPending = $this->commentRepository->countUnvalidated();
            if ($nbPending > 0) {
                $message = Lang::t('User comments') . ' <i class="icon-chat"></i> ';
                $message .= '<a href="' . $this->urlGenerator->admin('comments') . '">';
                $message .= Lang::t('%d waiting for validation', $nbPending);
                $message .= ' <i class="icon-right"></i></a>';
                PageState::current()->addMessage($message);
            }
        }

        $nb_orphans = $this->imageAdminService->countOrphans();

        if ($nb_orphans > 0) {
            $orphans_url = $this->urlGenerator->admin('batch_manager') . '&amp;filter=prefilter-no_album';
            $message     = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>' . Lang::t('Orphans') . '</a><span class="adminMenubarCounter">' . $nb_orphans . '</span>';
            PageState::current()->addWarning($message);
        }

        $locked_album = $this->categoryRepository->countHidden();
        if ($locked_album > 0) {
            $locked_album_url = $this->urlGenerator->admin('cat_options') . '&section=visible';
            $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>' . Lang::t('Locked album') . '</a><span class="adminMenubarCounter">' . $locked_album . '</span>';
            PageState::current()->addWarning($message);
        }

        $this->imageAdminService->fsQuickCheck();


        $intro_newsletter_data = null;
        if (Config::showNewsletterSubscription() && $this->preferencesService->userprefsGetParam('show_newsletter_subscription', true)) {
            $register_date = $this->userRepository->findEarliestRegistrationDate();
            $nb_cats       = $this->categoryRepository->countAll();
            $nb_images     = $this->imageRepository->countAll();
            $detect = new MobileDetect();
            if (!$detect->is('iOS') && strtotime((string) $register_date) < strtotime('2 weeks ago') && $nb_cats >= 3 && $nb_images >= 30) {
                $userLang  = CurrentUser::get()->language;
                $userEmail = CurrentUser::get()->email;
                $intro_newsletter_data = ['email' => $userEmail, 'subscribe_base_url' => $this->adminService->getNewsletterSubscribeBaseUrl($userLang), 'old_newsletters_url' => $this->adminService->getOldNewslettersBaseUrl($userLang), 'str_subscribe_title' => Lang::t('Subscribe to our newsletter and stay updated!'), 'str_subscribe_button' => Lang::t('Sign up to the newsletter'), 'str_see_previous' => Lang::t('See previous newsletters'), 'str_dismiss' => Lang::t('Understood, do not show again')];
            }
        }

        $stats      = $this->adminService->getPwgGeneralStatitics();
        $du_decimals = 1;
        $du_gb      = (float) $stats->diskUsage / (1024.0 * 1024.0);
        if ($du_gb > 100) {
            $du_decimals = 0;
        }

        $tpl->assign(['NB_PHOTOS' => $stats->nbPhotos, 'NB_ALBUMS' => $stats->nbCategories, 'NB_TAGS' => $stats->nbTags, 'NB_IMAGE_TAG' => $stats->nbImageTag, 'NB_USERS' => $stats->nbUsers, 'NB_GROUPS' => $stats->nbGroups, 'NB_RATES' => $stats->nbRates, 'NB_VIEWS' => $this->adminService->numberFormatHumanReadable((float) $stats->nbViews), 'NB_PLUGINS' => count($activePluginIds), 'STORAGE_USED' => new Html(str_replace(' ', '&nbsp;', Lang::t('%sGB', number_format($du_gb, $du_decimals)))), 'U_QUICK_SYNC' => $this->urlGenerator->admin('site_update') . '&site=1&quick_sync=1&pwg_token=' . $this->csrfService->getToken(), 'CHECK_FOR_UPDATES' => Config::dashboardCheckForUpdates()]);

        if (Config::activateComments()) {
            $tpl->assign('NB_COMMENTS', $this->commentRepository->countAll());
        } else {
            $tpl->assign('NB_COMMENTS', 0);
        }

        if (Config::showPiwigoLatestNews()) {
            $latest_news = $this->adminService->getPiwigoNews();
            if (isset($latest_news['id']) && $latest_news['posted_on'] > time() - 60 * 60 * 24 * 30) {
                $newsUrl     = $latest_news['url'] ?? null;
                $newsPosted  = $latest_news['posted'] ?? null;
                $newsSubject = $latest_news['subject'] ?? null;
                PageState::current()->addMessage(new Html(sprintf('%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>', Lang::t('Latest Piwigo news'), is_string($newsUrl) ? $newsUrl : '', $this->dateService->timeSince(is_string($latest_news['posted_on']) || is_int($latest_news['posted_on']) ? $latest_news['posted_on'] : null, 'year') . ' (' . (is_string($newsPosted) ? $newsPosted : '') . ')', is_string($newsSubject) ? $newsSubject : '')));
            }
        }

        $this->dispatcher->dispatch(new LocEndIntro());

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

        // Dashboard activity is global (not per-user) and the underlying
        // query is expensive on busy installs — cache it in the shared pool
        // with a 5-minute TTL. Key includes $nb_weeks because that's the
        // shape parameter; changing it invalidates the cached layout.
        $item = $this->pool->getItem('admin.dashboard.activity_last_weeks.' . $nb_weeks);
        if ($item->isHit()) {
            $cached = $item->get();
            $activity_last_weeks = is_array($cached) ? $cached : [];
        } else {
            $start_time = StringUtil::getMoment();
            $activity_actions = $this->activityRepository->findDailyActionCountsSince($date_string);

            foreach ($activity_actions as $action) {
                $day_date = new \DateTime($action->activityDay . ' 12:00:00');
                $week     = 0;
                for ($i = 0; $i < $nb_weeks; $i++) {
                    if ($week_number[$i] == $day_date->format('W')) {
                        $week = $i;
                    }
                }
                $day_nb = $day_date->format('N');
                $activity_last_weeks[$week][$day_nb]['details'][ucfirst($action->object)][ucfirst($action->action)] = $action->activityCounter;
                $activity_last_weeks[$week][$day_nb]['number'] = ($activity_last_weeks[$week][$day_nb]['number'] ?? 0) + $action->activityCounter;
                $activity_last_weeks[$week][$day_nb]['date']   = $this->dateService->formatDate($day_date->getTimestamp());
            }

            LoggerRegistry::current()->debug('[admin/intro::] recent activity calculated in ' . StringUtil::getElapsedTime($start_time, StringUtil::getMoment()));
            $item->set($activity_last_weeks);
            $item->expiresAfter(300);
            $this->pool->save($item);
        }

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

        $day_names  = Lang::days();
        $day_labels = [];
        for ($i = 0; $i <= 6; $i++) {
            $name         = $day_names[($i + 1) % 7] ?? '';
            $day_labels[] = mb_substr($name, 0, 3);
        }
        $tpl->assign('DAY_LABELS', $day_labels);

        $video_format = ['webm', 'webmv', 'ogg', 'ogv', 'mp4', 'm4v', 'mov'];
        $data_storage = [];
        foreach ($this->imageRepository->findFileExtensionTotals() as $ext => $ext_details) {
            $type = in_array(strtolower($ext), Config::pictureExtensions()) ? 'Photos' : (in_array(strtolower($ext), $video_format) ? 'Videos' : 'Other');
            $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + $ext_details->filesize;
            $data_storage[$type]['total']['nb_files']  = ($data_storage[$type]['total']['nb_files'] ?? 0) + $ext_details->extCounter;
            $data_storage[$type]['details'][strtoupper($ext)] = ['filesize' => $ext_details->filesize, 'nb_files' => $ext_details->extCounter];
        }
        foreach ($this->imageFormatRepository->findExtensionTotals() as $ext => $ext_details) {
            $type = 'Formats';
            $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + $ext_details->filesize;
            $data_storage[$type]['total']['nb_files']  = ($data_storage[$type]['total']['nb_files'] ?? 0) + $ext_details->extCounter;
            $data_storage[$type]['details'][strtoupper($ext)] = ['filesize' => $ext_details->filesize, 'nb_files' => $ext_details->extCounter];
        }

        if (Config::addCacheToStorageChart()) {
            $cacheSizesItem = $this->pool->getItem('piwigo.cache_sizes');
            if ($cacheSizesItem->isHit()) {
                $cache_sizes = $cacheSizesItem->get();
                if (is_array($cache_sizes) && isset($cache_sizes[0]) && is_array($cache_sizes[0]) && isset($cache_sizes[0]['value'])) {
                    $cacheFilesize = (is_numeric($cache_sizes[0]['value']) ? (float) $cache_sizes[0]['value'] : 0.0) / 1024.0;
                    $data_storage['Cache'] = ['total' => ['filesize' => $cacheFilesize, 'nb_files' => 0], 'details' => []];
                }
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
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'intro.latte');

        // C13yInternal's listener registration moved to
        // Piwigo\Listener\ListCheckIntegritySubscriber (boot-time).
        $c13y = new CheckIntegrity($this->integrityIgnoredAnomaliesRepo);
        $c13y->check();
        $c13y->display();
    }

    // ── menubar ───────────────────────────────────────────────────────────────

    private function menubar(): void
    {
        $tpl = TemplateRegistry::current();

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $tabsheet    = new Tabsheet();
        $tabsheet->setId('menus');
        $tabsheet->select('');
        $tabsheet->assign();

        $menu      = new BlockManager($this->dispatcher, $this->menubarLayout);
        $menu->loadRegisteredBlocks();
        $reg_blocks = $menu->getRegisteredBlocks();

        $mb_conf = $this->menubarLayout->load();

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

        if (isset($_POST['submit']) && $this->permissionService->isWebmaster()) {
            foreach ($mb_conf as $id => $pos) {
                $hide     = isset($_POST['hide_' . $id]);
                $mb_conf[$id] = ($hide ? -1 : +1) * abs($pos);
                $raw_pos  = $_POST['pos_' . $id] ?? null;
                $postPos  = is_scalar($raw_pos) ? (int) $raw_pos : 0;
                if ($postPos > 0) {
                    $mb_conf[$id] = $mb_conf[$id] > 0 ? $postPos : -$postPos;
                }
            }
            $this->makeConsecutive($mb_conf);
            $this->menubarLayout->save($mb_conf);
            $tpl->assign(['save_success' => Lang::t('Order of menubar items has been updated successfully.')]);
        }

        $this->makeConsecutive($mb_conf);

        foreach ($mb_conf as $id => $pos) {
            $tpl->append('blocks', ['pos' => $pos / 5, 'reg' => $reg_blocks[$id]]);
        }

        $action = $this->urlGenerator->admin('menubar');
        $tpl->assign(['F_ACTION' => $action]);
        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Menu Management'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'menubar.latte');
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

        $tpl->assign([
            'F_ACTION'          => $this->urlGenerator->admin('comments'),
            'PWG_TOKEN'         => $this->csrfService->getToken(),
            'COMMENTS_DISABLED' => !Config::activateComments(),
            'U_CONFIGURATION'   => $this->urlGenerator->admin('configuration') . '&section=comments',
            'page_data_json'    => json_encode([
                'pwg_token' => $this->csrfService->getToken(),
                'str_yes_delete_confirmation' => Lang::t('Yes, delete'), 'str_no_delete_confirmation' => Lang::t('No, I have changed my mind'), 'str_delete' => Lang::t('Are you sure you want to delete comment #%s?'), 'str_deletes' => Lang::t('Are you sure you want to delete "%d" comments?'), 'str_no_comments_selected' => Lang::t('No comments selected, no actions possible.'), 'str_an_error_has' => Lang::t('An error has occured'), 'str_comment_validated' => Lang::t('The comment has been validated.'), 'str_comments_validated' => Lang::t('The comments have been validated.'), 'str_and_others' => Lang::t('and %s others'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tabsheet    = new Tabsheet();
        $tabsheet->setId('comments');
        $tabsheet->select('');
        $tabsheet->assign();

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('User comments'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'comments.latte');
    }

    // ── rating ────────────────────────────────────────────────────────────────

    private function rating(): void
    {
        $tpl = TemplateRegistry::current();

        $this->inputValidator->check('display', $_GET, false, ValidationPattern::ID);

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating');
        $tabsheet->assign();

        $start           = isset($_GET['start']) && is_numeric($_GET['start']) ? (int) $_GET['start'] : 0;
        $elements_per_page = isset($_GET['display']) && is_numeric($_GET['display']) ? (int) $_GET['display'] : 10;
        $order_by_index  = isset($_GET['order_by']) && is_numeric($_GET['order_by']) ? (int) $_GET['order_by'] : 0;

        $userClass = 'all';
        if (isset($_GET['users']) && ($_GET['users'] === 'user' || $_GET['users'] === 'guest')) {
            $userClass = $_GET['users'];
        }

        $catFilterIds = [];
        if (isset($_GET['cat']) && is_numeric($_GET['cat'])) {
            $catFilterIds = array_values($this->categoryService->getSubcatIds([(int) $_GET['cat']]));
        }

        $userFields = Config::userFields();
        $users      = [];
        foreach ($this->userRepository->findAllUserIdNameMap($userFields->id, $userFields->username, Tables::users()) as $id => $username) {
            $users[$id] = stripslashes($username);
        }

        $nb_images   = $this->rateRepository->countDistinctRatedImagesAdmin($userClass, Config::guestId(), $catFilterIds);
        $nb_elements = $this->imageRepository->countRatings();

        $cache_keys  = $this->adminService->getAdminClientCacheKeys(['categories']);
        $rating_page_data = ['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => UrlService::getRootUrl(), 'str_create' => Lang::t('Create'), 'nb_elements' => $nb_elements];

        $tpl->assign(['navbar' => $this->paginationService->createNavigationBar($this->urlGenerator->admin() . $this->urlService->getQueryStringDiff(['start', 'del']), $nb_images, $start, $elements_per_page), 'F_ACTION' => $this->urlGenerator->admin(), 'DISPLAY' => $elements_per_page, 'NB_ELEMENTS' => $nb_elements, 'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []), 'CACHE_KEYS' => $cache_keys, 'rating_page_data_json' => json_encode($rating_page_data)]);

        $available_order_by = [[Lang::t('Rate date'), 'recently_rated DESC'], [Lang::t('Rating score'), 'score DESC'], [Lang::t('Average rate'), 'avg_rates DESC'], [Lang::t('Number of rates'), 'nb_rates DESC'], [Lang::t('Sum of rates'), 'sum_rates DESC'], [Lang::t('File name'), 'file DESC'], [Lang::t('Creation date'), 'date_creation DESC'], [Lang::t('Post date'), 'date_available DESC']];
        foreach ($available_order_by as $orderByEntry) {
            $tpl->append('order_by_options', $orderByEntry[0]);
        }
        $tpl->assign('order_by_options_selected', [$order_by_index]);

        $user_options = ['all' => Lang::t('all'), 'user' => Lang::t('Users'), 'guest' => Lang::t('Guests')];
        $tpl->assign('user_options', $user_options);
        $tpl->assign('user_options_selected', [$_GET['users'] ?? null]);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Rating'));

        $images = $this->rateRepository->findRatedImagesAdminPage(
            $userClass,
            Config::guestId(),
            $catFilterIds,
            $available_order_by[$order_by_index][1],
            $elements_per_page,
            $start,
        );
        $tpl->assign('images', []);
        foreach ($images as $image) {
            $thumbnail_src = DerivativeImage::thumbUrl(['id' => $image->id, 'path' => $image->path, 'file' => $image->file, 'representative_ext' => $image->representativeExt]);
            $image_url     = $this->urlGenerator->admin('photo-' . $image->id);
            $all_rates     = $this->rateRepository->findByElementId($image->id);
            $tpl_image     = ['id' => $image->id, 'U_THUMB' => $thumbnail_src, 'U_URL' => $image_url, 'SCORE_RATE' => $image->score, 'AVG_RATE' => $image->avgRates, 'SUM_RATE' => $image->sumRates, 'NB_RATES' => $image->nbRates, 'NB_RATES_TOTAL' => count($all_rates), 'FILE' => $image->file, 'rates' => []];
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
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'rating.latte');
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
        foreach ($this->userRepository->findAllWithStatus($userFields->id, $userFields->username, Tables::users()) as $row) {
            $rowStatusForPerm = UserStatus::tryFrom($row->status);
            $users_by_id[$row->id] = ['name' => $row->username, 'anon' => !$this->permissionService->isAutorizeStatus(AccessLevel::Classic, $rowStatusForPerm)];
        }

        $by_user_rating_model = ['rates' => []];
        foreach (Config::rateItems() as $rate) {
            $by_user_rating_model['rates'][$rate] = [];
        }

        $image_ids     = [];
        $by_user_ratings = [];
        foreach ($this->rateRepository->findAllOrderedByDate() as $row) {
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
            foreach ($this->imageRepository->findByIds(array_map(intval(...), array_keys($image_ids))) as $img) {
                $id = $img->id->value;
                $image_urls[$id] = [
                    'tn'   => DerivativeImage::url($params, SrcImage::fromImage($img)),
                    'page' => $this->urlService->makePictureUrl(['image_id' => $id, 'image_file' => $img->file->value]),
                ];
            }
        }

        $all_img_sum = [];
        foreach ($this->rateRepository->findAverageByElement() as $row) {
            $all_img_sum[$row->elementId] = ['avg' => $row->avgRate];
        }

        $best_rated = array_flip($this->imageRepository->findTopRatedIds($consensus_top_number));

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

        $nb_elements = $this->imageRepository->countRatings();
        $tpl->assign(['F_ACTION' => $this->urlGenerator->admin(), 'F_MIN_RATES' => $filter_min_rates, 'CONSENSUS_TOP_NUMBER' => $consensus_top_number, 'available_rates' => Config::rateItems(), 'ratings' => $by_user_ratings, 'image_urls' => $image_urls, 'TN_WIDTH' => ImageStdParams::getByType(DerivativeSize::Square->value)->sizing->ideal_size[0], 'NB_ELEMENTS' => $nb_elements, 'ADMIN_PAGE_TITLE' => Lang::t('Rating'), 'page_data_json' => json_encode(['nb_elements' => $nb_elements, 'root_url' => UrlService::getRootUrl(), 'str_delete_ratings_confirm' => Lang::t('Are you sure you want to delete the ratings of the user "%s"?')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)]);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'rating_user.latte');
    }

    // ── profile ───────────────────────────────────────────────────────────────

    private function profile(): void
    {
        $tpl = TemplateRegistry::current();

        $this->inputValidator->check('user_id', $_GET, false, ValidationPattern::ID);

        $userIdRaw = $_GET['user_id'] ?? null;
        $editUserId = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;
        $edit_user  = $this->userService->buildUser($editUserId, false);

        if (!empty($_POST)) {
            $this->csrfService->check();
        }

        $errors = [];
        $this->profileService->saveProfileFromPost($edit_user, $errors);

        $this->profileService->loadProfileInTemplate(
            $this->urlGenerator->admin('profile') . '&user_id=' . (is_scalar($edit_user['id'] ?? null) ? (string) $edit_user['id'] : ''),
            $this->urlGenerator->admin('user_list'),
            $edit_user
        );

        foreach ($errors as $err) {
            PageState::current()->addError($err);
        }

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'profile.latte');
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
                $time_refresh     = $treated_count !== 0 ? (int) ceil((StringUtil::getMoment() - $ctx->startTime) * (float) $post_count / (float) $treated_count) : 0;
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
        $notifRepo = $this->notificationRepository;
        $userFields = Config::userFields();

        $notifRepo->clearEmptyEmails($userFields->email, Tables::users());
        $users_without_notif = $notifRepo->findUsersWithoutNotification($userFields->id, $userFields->username, $userFields->email, Tables::users());

        if (count($users_without_notif) > 0) {
            $inserts        = [];
            $check_key_list = [];
            foreach ($users_without_notif as $nbm_user) {
                $check_key         = $this->notificationAdminService->findAvailableCheckKey();
                $check_key_list[]  = $check_key;
                $inserts[]         = ['user_id' => $nbm_user->userId, 'check_key' => $check_key, 'enabled' => 0];
                PageState::current()->addInfo(Lang::t('User %s [%s] added.', stripslashes($nbm_user->username), $nbm_user->mailAddress));
            }
            $this->notificationRepository->insertSubscriptionsBatch($inserts);
            $check_key_treated = $this->notificationAdminService->doSubscribeUnsubscribeNotificationByMail(true, Config::nbmDefaultValueUserEnabled(), $check_key_list);

            if ($ctx->isSendmailTimeout) {
                $untreated_keys = array_diff($check_key_list, $check_key_treated);
                if (count($untreated_keys) != 0) {
                    $this->notificationRepository->deleteByCheckKeys(array_values($untreated_keys));
                    $this->redirectResponder->redirect($base_url . $this->urlService->getQueryStringDiff([], false), Lang::t('Operation in progress') . "\n" . Lang::t('Please wait...'));
                }
            }
        }
    }

    /**
     * @param string[] $check_key_list
     * @return array<mixed>
     */
    private function doActionSendMailNotification(string $action = 'list_to_send', array $check_key_list = [], string $customize_mail_content = ''): array
    {
        $ctx         = MailNotificationContext::current();
        $return_list = [];

        if (!in_array($action, ['list_to_send', 'send'])) {
            return $return_list;
        }

        $dbnow         = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $is_action_send = ($action == 'send');
        $data_users    = $this->notificationAdminService->getUserNotifications('send', $check_key_list);
        $is_list_all_without_test = ($ctx->isSendmailTimeout || Config::nbmListAllEnabledUsersToSend());

        if ($is_list_all_without_test && !$is_action_send) {
            return $data_users;
        }

        if ($data_users === []) {
            if ($is_action_send) {
                PageState::current()->addError(Lang::t('No user to send notifications by mail.'));
            }
            return $return_list;
        }
        $datas = [];
        if (empty($customize_mail_content)) {
            $customize_mail_content = Config::nbmComplementaryMailContent();
        }
        $cmcEvent  = new NbmRenderGlobalCustomizeMailContent($customize_mail_content);
        $this->dispatcher->dispatch($cmcEvent);
        $customize_mail_content = $cmcEvent->customizeMailContent;
        $msg_break_timeout = $is_action_send ? Lang::t('Time to send mail is limited. Others mails are skipped.') : Lang::t('Prepared time for list of users to send mail is limited. Others users are not listed.');

        $this->notificationAdminService->beginUsersEnvNbm($is_action_send);
        foreach ($data_users as $nbm_user) {
            if (!$is_action_send && $this->notificationAdminService->checkSendmailTimeout()) {
                PageState::current()->addInfo($msg_break_timeout);
                break;
            }
            if ($is_action_send && $this->notificationAdminService->checkSendmailTimeout()) {
                PageState::current()->addError($msg_break_timeout);
                break;
            }

            $this->notificationAdminService->setUserOnEnvNbm($nbm_user, $is_action_send);

            if ($is_action_send) {
                $auth = null;
                $url_params = [];
                $auth_key = $this->authService->createUserAuthKey(is_numeric($nbm_user['user_id']) ? (int) $nbm_user['user_id'] : 0, is_string($nbm_user['status']) ? $nbm_user['status'] : null);
                if (is_array($auth_key) && is_string($auth_key['auth_key'] ?? null)) {
                    $auth = $auth_key['auth_key'];
                    $url_params['auth'] = $auth;
                }

                $this->urlService->setMakeFullUrl();
                $return_list[] = (string) $nbm_user['check_key'];
                $last_send     = is_string($nbm_user['last_send']) || is_null($nbm_user['last_send']) ? $nbm_user['last_send'] : (string) $nbm_user['last_send'];

                $news = [];
                if (Config::nbmSendDetailedContent()) {
                    $news = $this->notificationService->news($last_send, $dbnow, false, Config::nbmSendHtmlMail(), $auth);
                    $exist_data = count($news) > 0;
                } else {
                    $exist_data = $this->notificationService->newsExists($last_send, $dbnow);
                }

                if ($exist_data && $this->notificationAdminService->sendNotificationEmailToUser($nbm_user, $dbnow, $customize_mail_content, $news, $auth, $url_params)) {
                    $datas[] = ['user_id' => is_numeric($nbm_user['user_id']) ? (int) $nbm_user['user_id'] : 0, 'last_send' => $dbnow];
                }
            } else {
                $last_send = isset($nbm_user['last_send']) ? (string) $nbm_user['last_send'] : null;
                if ($this->notificationService->newsExists($last_send, $dbnow)) {
                    $return_list[] = $nbm_user;
                }
            }
            $this->notificationAdminService->unsetUserOnEnvNbm();
        }
        $this->notificationAdminService->endUsersEnvNbm();

        if ($is_action_send) {
            $this->notificationRepository->setLastSendBatch($datas);
            $this->notificationAdminService->displayCounterInfo();
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
                $base_url .= $is_first ? '?' : '&';
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
                    $url = $this->urlService->addUrlParams($url, [$get_param => $field]);
                } elseif (!isset($_GET[$get_param])) {
                    $ret[] = $field;
                    $disp = '<em>' . $disp . '</em>';
                }
            } else {
                $ret[] = $field;
                $disp = '<em>' . $disp . '</em>';
            }
            if (isset($template_var)) {
                $tpl->assign($template_var . strtoupper($field), new Html('<a href="' . $url . $anchor . '" title="' . htmlspecialchars(Lang::t('Sort order')) . '">' . $disp . '</a>'));
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
    /** @param array<string, int> $orders */
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
