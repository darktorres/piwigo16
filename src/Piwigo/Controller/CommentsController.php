<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the paginated comments list page (/comments).
 * Corresponds to the former comments.php entry-point.
 */
final class CommentsController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!Config::activateComments()) {
            ServiceLocator::get(HtmlService::class)->pageNotFound(null);
        }

        PermissionService::get()->checkStatus(AccessLevel::Guest);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $url_self   = ServiceLocator::get(UrlGenerator::class)->comments() . UrlService::get()->getQueryStringDiff(['delete', 'edit', 'validate', 'pwg_token']);
        $sort_order = ['DESC' => Lang::t('descending'), 'ASC' => Lang::t('ascending')];
        $sort_by    = ['date' => Lang::t('comment date'), 'image_id' => Lang::t('photo')];
        $items_number = [5, 10, 20, 50, 'all'];

        if (!in_array(Config::commentsPageNbComments(), $items_number)) {
            $items_number_new = [];
            $is_inserted      = false;
            foreach ($items_number as $number) {
                if ($number > Config::commentsPageNbComments() || ($number == 'all' && !$is_inserted)) {
                    $items_number_new[] = Config::commentsPageNbComments();
                    $is_inserted        = true;
                }
                $items_number_new[] = $number;
            }
            $items_number = $items_number_new;
        }

        $since_options = [
            1 => ['label' => Lang::t('today'),              'clause' => 'date > ' . SqlExpr::recentPeriodExpr(1)],
            2 => ['label' => Lang::t('last %d days', 7),    'clause' => 'date > ' . SqlExpr::recentPeriodExpr(7)],
            3 => ['label' => Lang::t('last %d days', 30),   'clause' => 'date > ' . SqlExpr::recentPeriodExpr(30)],
            4 => ['label' => Lang::t('the beginning'),      'clause' => '1=1'],
        ];

        EventDispatcher::notify('loc_begin_comments');

        $get_since = StringUtil::get()->inputInt('since', null, $_GET);
        $page['since'] = ($get_since !== null && $get_since !== 0) ? $get_since : 4;

        $page['sort_by'] = 'date';
        $get_sort_by = StringUtil::get()->inputString('sort_by', null, $_GET);
        if ($get_sort_by !== null && isset($sort_by[$get_sort_by])) {
            $page['sort_by'] = $get_sort_by;
        }

        $page['sort_order'] = 'DESC';
        $get_sort_order = StringUtil::get()->inputString('sort_order', null, $_GET);
        if ($get_sort_order !== null && isset($sort_order[$get_sort_order])) {
            $page['sort_order'] = $get_sort_order;
        }

        $page['items_number'] = Config::commentsPageNbComments();
        $get_items_number = StringUtil::get()->inputString('items_number', null, $_GET);
        if ($get_items_number !== null) {
            $page['items_number'] = $get_items_number;
        }
        if (!is_numeric($page['items_number']) && $page['items_number'] != 'all') {
            $page['items_number'] = 10;
        }

        $page['where_clauses'] = [];

        $get_cat = StringUtil::get()->inputInt('cat', null, $_GET);
        if ($get_cat !== null && 0 != $get_cat) {
            ServiceLocator::get(Util::class)->checkInputParameter('cat', $_GET, false, ValidationPattern::ID);
            $category_ids = ServiceLocator::get(CategoryService::class)->getSubcatIds([$get_cat]);
            if (empty($category_ids)) {
                $category_ids = [-1];
            }
            $page['where_clauses'][] = 'category_id IN (' . implode(',', $category_ids) . ')';
        }

        $get_author = StringUtil::get()->inputString('author', null, $_GET);
        if ($get_author !== null && $get_author !== '') {
            $page['where_clauses'][] = '(u.' . Config::userFields()['username'] . ' = \'' . $get_author . '\' OR author = \'' . $get_author . '\')';
        }

        $get_comment_id_filter = StringUtil::get()->inputInt('comment_id', null, $_GET);
        if ($get_comment_id_filter !== null && $get_comment_id_filter !== 0) {
            ServiceLocator::get(Util::class)->checkInputParameter('comment_id', $_GET, false, ValidationPattern::ID);
            if (!PermissionService::get()->isAdmin()) {
                $requestUriRaw = $_SERVER['REQUEST_URI'] ?? null;
                $requestUri = is_string($requestUriRaw) ? $requestUriRaw : '';
                $login_url  = UrlService::get()->addUrlParams(ServiceLocator::get(UrlGenerator::class)->identification(), ['redirect' => urlencode($requestUri)]);
                Util::get()->redirect($login_url);
            }
            $page['where_clauses'][] = 'com.id = ' . $get_comment_id_filter;
        }

        $get_keyword = StringUtil::get()->inputString('keyword', null, $_GET);
        if ($get_keyword !== null && $get_keyword !== '') {
            $page['where_clauses'][] = '(' . implode(' AND ', array_map(
                fn (string $s): string => "content LIKE '%$s%'",
                preg_split('/[\s,;]+/', $get_keyword) ?: []
            )) . ')';
        }

        $pageSinceRaw = $page['since'];
        $pageSince    = in_array($pageSinceRaw, [1, 2, 3, 4]) ? $pageSinceRaw : 4;
        $page['where_clauses'][] = $since_options[$pageSince]['clause'];

        if (!PermissionService::get()->isAdmin()) {
            $page['where_clauses'][] = 'validated=\'true\'';
        }

        $page['where_clauses'][] = PermissionService::get()->getSqlConditionFandF(
            ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'ic.image_id'],
            '',
            true
        );

        // Comment actions
        $comment_id = 0;
        $action     = null;
        $edit_comment = null;

        foreach (['delete', 'validate', 'edit'] as $loop_action) {
            if (isset($_GET[$loop_action])) {
                $action = $loop_action;
                ServiceLocator::get(Util::class)->checkInputParameter($action, $_GET, false, ValidationPattern::ID);
                $actionRaw = $_GET[$action] ?? null;
                $comment_id = is_numeric($actionRaw) ? (int) $actionRaw : 0;
                break;
            }
        }

        if (isset($action)) {
            $comment_author_id = ServiceLocator::get(CommentService::class)->getCommentAuthorId($comment_id);
            if (PermissionService::get()->canManageComment($action, $comment_author_id)) {
                $perform_redirect = false;
                if ('delete' == $action) {
                    ServiceLocator::get(Util::class)->checkPwgToken();
                    ServiceLocator::get(CommentService::class)->deleteUserComment($comment_id);
                    $perform_redirect = true;
                }
                if ('validate' == $action) {
                    ServiceLocator::get(Util::class)->checkPwgToken();
                    ServiceLocator::get(CommentService::class)->validateUserComment($comment_id);
                    $perform_redirect = true;
                }
                if ('edit' == $action) {
                    $post_content = StringUtil::get()->inputString('content', null, $_POST);
                    if ($post_content !== null && $post_content !== '') {
                        ServiceLocator::get(Util::class)->checkPwgToken();
                        $comment_action = ServiceLocator::get(CommentService::class)->updateUserComment(
                            ['comment_id' => $comment_id, 'image_id' => StringUtil::get()->inputInt('image_id', null, $_POST), 'content' => $post_content, 'website_url' => StringUtil::get()->inputString('website_url', null, $_POST)],
                            StringUtil::get()->inputString('key', null, $_POST) ?? ''
                        );
                        switch ($comment_action) {
                            case 'moderate':
                                if (!is_array($_SESSION['page_infos'] ?? null)) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = Lang::t('An administrator must authorize your comment before it is visible.');
                                // no break
                            case 'validate':
                                if (!is_array($_SESSION['page_infos'] ?? null)) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = Lang::t('Your comment has been registered');
                                $perform_redirect = true;
                                break;
                            case 'reject':
                                if (!is_array($_SESSION['page_errors'] ?? null)) {
                                    $_SESSION['page_errors'] = [];
                                }
                                $_SESSION['page_errors'][] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
                                break;
                            default:
                                trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                        }
                    }
                    $edit_comment = $comment_id;
                }
                if ($perform_redirect) {
                    Util::get()->redirect($url_self);
                }
            }
        }

        $title = Lang::t('User comments');
        $page['body_id'] = 'theCommentsPage';

        $tpl = TemplateRegistry::current();
        $tpl->assign([
            'F_ACTION'  => ServiceLocator::get(UrlGenerator::class)->comments(),
            'F_KEYWORD' => ($get_keyword !== null && $get_keyword !== '') ? htmlspecialchars(stripslashes($get_keyword)) : '',
            'F_AUTHOR'  => ($get_author !== null && $get_author !== '') ? htmlspecialchars(stripslashes($get_author)) : '',
        ]);

        $blockname = 'categories';
        $query = '
SELECT id, name, uppercats, global_rank
  FROM ' . Tables::categories() . '
' . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'WHERE') . '
;';
        ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query, array_filter([$get_cat], fn (mixed $v): bool => $v !== null), $blockname, true);

        $tpl_var = [];
        foreach ($since_options as $id => $option) {
            $tpl_var[$id] = $option['label'];
        }
        $tpl->assign('since_options', $tpl_var);
        $tpl->assign('since_options_selected', $pageSince);
        $tpl->assign('sort_by_options', $sort_by);
        $tpl->assign('sort_by_options_selected', $page['sort_by']);
        $tpl->assign('sort_order_options', $sort_order);
        $tpl->assign('sort_order_options_selected', $page['sort_order']);

        $tpl_var = [];
        foreach ($items_number as $option) {
            $tpl_var[$option] = is_numeric($option) ? $option : Lang::t($option);
        }
        $tpl->assign('item_number_options', $tpl_var);
        $tpl->assign('item_number_options_selected', $page['items_number']);

        $start        = StringUtil::get()->inputInt('start', 0, $_GET);
        $comments     = [];
        $element_ids  = [];
        $category_ids = [];

        $userEmailField = Config::userFields()['email'];
        $userIdField    = Config::userFields()['id'];
        $joinBase = '
  FROM ' . Tables::imageCategory() . ' AS ic
    INNER JOIN ' . Tables::comments() . ' AS com ON ic.image_id = com.image_id
    LEFT JOIN ' . Tables::users() . ' AS u ON u.' . $userIdField . ' = com.author_id
  WHERE ' . implode("\n    AND ", $page['where_clauses']);

        $conn    = ServiceLocator::get(Connection::class);
        $counter = $conn->executeQuery('SELECT COUNT(DISTINCT com.id)' . $joinBase)->fetchOne();

        $pageItemsNumber = $page['items_number'];
        $dataQuery = 'SELECT com.id AS comment_id, com.image_id, ic.category_id, com.author, com.author_id,'
            . " u.$userEmailField AS user_email, com.email, com.date, com.website_url, com.content, com.validated"
            . $joinBase . '
  GROUP BY comment_id
  ORDER BY ' . $page['sort_by'] . ' ' . $page['sort_order'];
        if ('all' != $pageItemsNumber) {
            $dataQuery .= '
  LIMIT ' . $pageItemsNumber . ' OFFSET ' . ($start ?? 0);
        }

        foreach ($conn->executeQuery($dataQuery)->fetchAllAssociative() as $row) {
            $comments[]     = $row;
            $element_ids[]  = $row['image_id'];
            $category_ids[] = $row['category_id'];
        }

        $url     = ServiceLocator::get(UrlGenerator::class)->comments() . UrlService::get()->getQueryStringDiff(['start', 'edit', 'delete', 'validate', 'pwg_token']);
        $navbar  = ServiceLocator::get(Util::class)->createNavigationBar(
            $url,
            is_numeric($counter) ? (int) $counter : 0,
            $start ?? 0,
            is_numeric($pageItemsNumber) ? (int) $pageItemsNumber : 0,
            false
        );
        $tpl->assign('navbar', $navbar);

        if (count($comments) > 0) {
            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $element_ids)) . ')
;';
            $elements = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');

            $query = 'SELECT id, name, permalink, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $category_ids)) . ')';
            $categories = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');

            foreach ($comments as $comment) {
                /** @var array<string, float|int|string|null> $comment */
                $cImageId     = is_numeric($comment['image_id']) ? (int) $comment['image_id'] : 0;
                $cCategoryId  = is_numeric($comment['category_id']) ? (int) $comment['category_id'] : 0;
                $cId          = is_numeric($comment['comment_id']) ? (int) $comment['comment_id'] : 0;
                $cAuthorId    = is_numeric($comment['author_id']) ? (int) $comment['author_id'] : 0;

                /** @var array<string, float|int|string|null> $element_row */
                $element_row  = $elements[(string) $cImageId] ?? [];
                $name         = (isset($element_row['name']) && $element_row['name'] !== '') ? (string) $element_row['name'] : ServiceLocator::get(StringUtil::class)->getNameFromFile((string) ($element_row['file'] ?? ''));
                $src_image    = new SrcImage($element_row);
                $url          = UrlService::get()->makePictureUrl([
                    'category'   => $categories[(string) $cCategoryId] ?? [],
                    'image_id'   => $cImageId,
                    'image_file' => (string) ($element_row['file'] ?? ''),
                ]);

                $email = null;
                if (isset($comment['user_email']) && $comment['user_email'] !== '') {
                    $email = (string) $comment['user_email'];
                } elseif (isset($comment['email']) && $comment['email'] !== '') {
                    $email = (string) $comment['email'];
                }

                $cDate = $comment['date'] !== null ? (string) $comment['date'] : null;
                $tpl_comment = [
                    'ID'          => $cId,
                    'U_PICTURE'   => $url,
                    'src_image'   => $src_image,
                    'ALT'         => $name,
                    'AUTHOR'      => EventDispatcher::dispatch('render_comment_author', (string) ($comment['author'] ?? '')),
                    'WEBSITE_URL' => $comment['website_url'],
                    'DATE'        => ServiceLocator::get(DateService::class)->formatDate($cDate, ['day_name', 'day', 'month', 'year', 'time']),
                    'CONTENT'     => new Html((string) EventDispatcher::dispatch('render_comment_content', (string) ($comment['content'] ?? ''))),
                ];

                if (PermissionService::get()->isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }
                if (PermissionService::get()->canManageComment('delete', $cAuthorId)) {
                    $tpl_comment['U_DELETE'] = UrlService::get()->addUrlParams($url_self, ['delete' => $cId, 'pwg_token' => ServiceLocator::get(Util::class)->getPwgToken()]);
                }
                if (PermissionService::get()->canManageComment('edit', $cAuthorId)) {
                    $tpl_comment['U_EDIT'] = UrlService::get()->addUrlParams($url_self, ['edit' => $cId]);
                    if ($edit_comment !== null && $cId == $edit_comment) {
                        $key = ServiceLocator::get(Util::class)->getEphemeralKey(2, (string) $cImageId);
                        $tpl_comment['IN_EDIT']   = true;
                        $tpl_comment['KEY']       = $key;
                        $tpl_comment['IMAGE_ID']  = $cImageId;
                        $tpl_comment['CONTENT']   = (string) ($comment['content'] ?? '');
                        $tpl_comment['PWG_TOKEN'] = ServiceLocator::get(Util::class)->getPwgToken();
                        $tpl_comment['U_CANCEL']  = $url_self;
                    }
                }
                if (PermissionService::get()->canManageComment('validate', $cAuthorId) && 'true' != $comment['validated']) {
                    $tpl_comment['U_VALIDATE'] = UrlService::get()->addUrlParams($url_self, ['validate' => $cId, 'pwg_token' => ServiceLocator::get(Util::class)->getPwgToken()]);
                }
                $tpl->append('comments', $tpl_comment);
            }
        }

        $derivative_params = EventDispatcher::dispatch('get_comments_derivative_params', ImageStdParams::getByType(DerivativeSize::Thumb->value));
        $tpl->assign('comment_derivative_params', $derivative_params);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theCommentsPage', $hideMenuOn)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_comments');
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        if (count($comments) > 0) {
            $tpl->assignVarFromTemplate('COMMENT_LIST', 'comment_list.latte');
        }
        $tpl->pparse('comments.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
