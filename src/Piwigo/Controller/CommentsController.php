<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
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
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!Config::activateComments()) {
            page_not_found(null);
        }

        PermissionService::get()->checkStatus(AccessLevel::Guest);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $url_self   = ServiceLocator::get(UrlGenerator::class)->comments() . UrlService::get()->getQueryStringDiff(['delete', 'edit', 'validate', 'pwg_token']);
        $sort_order = ['DESC' => l10n('descending'), 'ASC' => l10n('ascending')];
        $sort_by    = ['date' => l10n('comment date'), 'image_id' => l10n('photo')];
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
            1 => ['label' => l10n('today'),              'clause' => 'date > ' . SqlExpr::recentPeriodExpr(1)],
            2 => ['label' => l10n('last %d days', 7),    'clause' => 'date > ' . SqlExpr::recentPeriodExpr(7)],
            3 => ['label' => l10n('last %d days', 30),   'clause' => 'date > ' . SqlExpr::recentPeriodExpr(30)],
            4 => ['label' => l10n('the beginning'),      'clause' => '1=1'],
        ];

        EventDispatcher::notify('loc_begin_comments');

        $get_since = input_int('since', null, $_GET);
        $page['since'] = !empty($get_since) ? $get_since : 4;

        $page['sort_by'] = 'date';
        $get_sort_by = input_string('sort_by', null, $_GET);
        if ($get_sort_by !== null && isset($sort_by[$get_sort_by])) {
            $page['sort_by'] = $get_sort_by;
        }

        $page['sort_order'] = 'DESC';
        $get_sort_order = input_string('sort_order', null, $_GET);
        if ($get_sort_order !== null && isset($sort_order[$get_sort_order])) {
            $page['sort_order'] = $get_sort_order;
        }

        $page['items_number'] = Config::commentsPageNbComments();
        $get_items_number = input_string('items_number', null, $_GET);
        if ($get_items_number !== null) {
            $page['items_number'] = $get_items_number;
        }
        if (!is_numeric($page['items_number']) && $page['items_number'] != 'all') {
            $page['items_number'] = 10;
        }

        $page['where_clauses'] = [];

        $get_cat = input_int('cat', null, $_GET);
        if ($get_cat !== null && 0 != $get_cat) {
            check_input_parameter('cat', $_GET, false, ValidationPattern::ID);
            $category_ids = get_subcat_ids([$get_cat]);
            if (empty($category_ids)) {
                $category_ids = [-1];
            }
            $page['where_clauses'][] = 'category_id IN (' . implode(',', $category_ids) . ')';
        }

        $get_author = input_string('author', null, $_GET);
        if (!empty($get_author)) {
            $page['where_clauses'][] = '(u.' . Config::userFields()['username'] . ' = \'' . $get_author . '\' OR author = \'' . $get_author . '\')';
        }

        $get_comment_id_filter = input_int('comment_id', null, $_GET);
        if (!empty($get_comment_id_filter)) {
            check_input_parameter('comment_id', $_GET, false, ValidationPattern::ID);
            if (!PermissionService::get()->isAdmin()) {
                $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
                $login_url  = UrlService::get()->addUrlParams(ServiceLocator::get(UrlGenerator::class)->identification(), ['redirect' => urlencode($requestUri)]);
                redirect($login_url);
            }
            $page['where_clauses'][] = 'com.id = ' . $get_comment_id_filter;
        }

        $get_keyword = input_string('keyword', null, $_GET);
        if (!empty($get_keyword)) {
            $page['where_clauses'][] = '(' . implode(' AND ', array_map(
                fn (string $s): string => "content LIKE '%$s%'",
                preg_split('/[\s,;]+/', $get_keyword) ?: []
            )) . ')';
        }

        $pageSinceRaw = $page['since'];
        $pageSince    = in_array($pageSinceRaw, [1, 2, 3, 4]) ? (int) $pageSinceRaw : 4;
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
                check_input_parameter($action, $_GET, false, ValidationPattern::ID);
                $comment_id = is_numeric($_GET[$action]) ? (int) $_GET[$action] : 0;
                break;
            }
        }

        if (isset($action)) {
            $comment_author_id = get_comment_author_id($comment_id);
            if (PermissionService::get()->canManageComment($action, (int) $comment_author_id)) {
                $perform_redirect = false;
                if ('delete' == $action) {
                    check_pwg_token();
                    delete_user_comment($comment_id);
                    $perform_redirect = true;
                }
                if ('validate' == $action) {
                    check_pwg_token();
                    validate_user_comment($comment_id);
                    $perform_redirect = true;
                }
                if ('edit' == $action) {
                    $post_content = input_string('content', null, $_POST);
                    if (!empty($post_content)) {
                        check_pwg_token();
                        $comment_action = update_user_comment(
                            ['comment_id' => $comment_id, 'image_id' => input_int('image_id', null, $_POST), 'content' => $post_content, 'website_url' => input_string('website_url', null, $_POST)],
                            input_string('key', null, $_POST) ?? ''
                        );
                        switch ($comment_action) {
                            case 'moderate':
                                if (!is_array($_SESSION['page_infos'] ?? null)) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = l10n('An administrator must authorize your comment before it is visible.');
                                // no break
                            case 'validate':
                                if (!is_array($_SESSION['page_infos'] ?? null)) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = l10n('Your comment has been registered');
                                $perform_redirect = true;
                                break;
                            case 'reject':
                                if (!is_array($_SESSION['page_errors'] ?? null)) {
                                    $_SESSION['page_errors'] = [];
                                }
                                $_SESSION['page_errors'][] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
                                break;
                            default:
                                trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                        }
                    }
                    $edit_comment = $comment_id;
                }
                if ($perform_redirect) {
                    redirect($url_self);
                }
            }
        }

        $title = l10n('User comments');
        $page['body_id'] = 'theCommentsPage';

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['comments' => 'comments.tpl', 'comment_list' => 'comment_list.tpl']);
        $tpl->assign([
            'F_ACTION'  => ServiceLocator::get(UrlGenerator::class)->comments(),
            'F_KEYWORD' => !empty($get_keyword) ? htmlspecialchars(stripslashes($get_keyword)) : '',
            'F_AUTHOR'  => !empty($get_author) ? htmlspecialchars(stripslashes($get_author)) : '',
        ]);

        $blockname = 'categories';
        $query = '
SELECT id, name, uppercats, global_rank
  FROM ' . Tables::categories() . '
' . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'WHERE') . '
;';
        display_select_cat_wrapper($query, array_filter([$get_cat], fn (mixed $v): bool => $v !== null), $blockname, true);

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
            $tpl_var[$option] = is_numeric($option) ? $option : l10n($option);
        }
        $tpl->assign('item_number_options', $tpl_var);
        $tpl->assign('item_number_options_selected', $page['items_number']);

        $start        = input_int('start', 0, $_GET);
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
        $navbar  = create_navigation_bar(
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
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $element_ids)) . ')
;';
            $elements = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');

            $query = 'SELECT id, name, permalink, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $category_ids)) . ')';
            $categories = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');

            foreach ($comments as $comment) {
                /** @var array<string, float|int|string|null> $comment */
                $cImageId     = is_numeric($comment['image_id']) ? (int) $comment['image_id'] : 0;
                $cCategoryId  = is_numeric($comment['category_id']) ? (int) $comment['category_id'] : 0;
                $cId          = is_numeric($comment['comment_id']) ? (int) $comment['comment_id'] : 0;
                $cAuthorId    = is_numeric($comment['author_id']) ? (int) $comment['author_id'] : 0;

                /** @var array<string, float|int|string|null> $element_row */
                $element_row  = $elements[(string) $cImageId] ?? [];
                $name         = !empty($element_row['name']) ? (string) $element_row['name'] : get_name_from_file((string) ($element_row['file'] ?? ''));
                $src_image    = new SrcImage($element_row);
                $url          = UrlService::get()->makePictureUrl([
                    'category'   => $categories[(string) $cCategoryId] ?? [],
                    'image_id'   => $cImageId,
                    'image_file' => (string) ($element_row['file'] ?? ''),
                ]);

                $email = null;
                if (!empty($comment['user_email'])) {
                    $email = (string) $comment['user_email'];
                } elseif (!empty($comment['email'])) {
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
                    'DATE'        => format_date($cDate, ['day_name', 'day', 'month', 'year', 'time']),
                    'CONTENT'     => EventDispatcher::dispatch('render_comment_content', (string) ($comment['content'] ?? '')),
                ];

                if (PermissionService::get()->isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }
                if (PermissionService::get()->canManageComment('delete', $cAuthorId)) {
                    $tpl_comment['U_DELETE'] = UrlService::get()->addUrlParams($url_self, ['delete' => $cId, 'pwg_token' => get_pwg_token()]);
                }
                if (PermissionService::get()->canManageComment('edit', $cAuthorId)) {
                    $tpl_comment['U_EDIT'] = UrlService::get()->addUrlParams($url_self, ['edit' => $cId]);
                    if ($edit_comment !== null && $cId == $edit_comment) {
                        $key = get_ephemeral_key(2, (string) $cImageId);
                        $tpl_comment['IN_EDIT']   = true;
                        $tpl_comment['KEY']       = $key;
                        $tpl_comment['IMAGE_ID']  = $cImageId;
                        $tpl_comment['CONTENT']   = (string) ($comment['content'] ?? '');
                        $tpl_comment['PWG_TOKEN'] = get_pwg_token();
                        $tpl_comment['U_CANCEL']  = $url_self;
                    }
                }
                if (PermissionService::get()->canManageComment('validate', $cAuthorId) && 'true' != $comment['validated']) {
                    $tpl_comment['U_VALIDATE'] = UrlService::get()->addUrlParams($url_self, ['validate' => $cId, 'pwg_token' => get_pwg_token()]);
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
        flush_page_messages();
        if (count($comments) > 0) {
            $tpl->assignVarFromHandle('COMMENT_LIST', 'comment_list');
        }
        $tpl->pparse('comments');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
