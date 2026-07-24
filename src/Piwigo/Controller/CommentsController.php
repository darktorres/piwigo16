<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces comments.php -- the front-end "all comments" listing + per-
 * comment moderation actions (delete/validate/edit). The "comments
 * management" block (delete/validate/edit, including its own
 * check_pwg_token()/redirect() calls) all happens before any rendering
 * starts.
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class CommentsController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn));
    }

    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(new CategoryRepository($conn), self::permissionService($conn));
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // A single connection for the whole request -- mirrors the
        // legacy single-global-mysqli-connection model this migration
        // restores, and avoids the needless-reconnection pattern found
        // in earlier construction-chain debt (Phase 1d finding).
        $conn = DbConnection::build();

        if (! \Piwigo\Config\CurrentConfig::activateComments()) {
            new HtmlService()
                ->pageNotFound($this->redirectService, null);
        }

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $url_self = $this->urlService->getRootUrl() . 'comments.php'
          . $this->urlService->getQueryStringDiff(['delete', 'edit', 'validate', 'pwg_token']);

        $sort_order = [
            'DESC' => Lang::t('descending'),
            'ASC' => Lang::t('ascending'),
        ];

        // sort_by : database fields proposed for sorting comments list
        $sort_by = [
            'date' => Lang::t('comment date'),
            'image_id' => Lang::t('photo'),
        ];

        // items_number : list of number of items to display per page
        $items_number = [5, 10, 20, 50, 'all'];

        // \Piwigo\Config\CurrentConfig::commentsPageNbComments() is a plain int setting (see
        // include/config_default.inc.php); $conf itself is only known as
        // array<string, mixed>, so narrow with a fallback matching the
        // shipped default rather than trust the shape blindly.
        $comments_page_nb_comments = \Piwigo\Config\CurrentConfig::commentsPageNbComments();

        // if the default value is not in the expected values, we add it in
        // the $items_number array (only compare against the numeric options
        // -- 'all' can never match an int, so filter it out rather than let
        // Psalm/PHPStan flag that one array element as an impossible
        // comparison).
        if (! in_array($comments_page_nb_comments, array_filter($items_number, is_int(...)), true)) {
            $items_number_new = [];

            $is_inserted = false;

            foreach ($items_number as $number) {
                if ((is_int($number) && $number > $comments_page_nb_comments) or ($number === 'all' and ! $is_inserted)) {
                    $items_number_new[] = $comments_page_nb_comments;
                    $is_inserted = true;
                }

                $items_number_new[] = $number;
            }

            $items_number = $items_number_new;
        }

        // since when display comments ?
        //
        $since_options = [
            1 => [
                'label' => Lang::t('today'),
                'clause' => 'date > ' . SqlDialect::getRecentPeriodExpression(1),
            ],
            2 => [
                'label' => Lang::t('last %d days', 7),
                'clause' => 'date > ' . SqlDialect::getRecentPeriodExpression(7),
            ],
            3 => [
                'label' => Lang::t('last %d days', 30),
                'clause' => 'date > ' . SqlDialect::getRecentPeriodExpression(30),
            ],
            4 => [
                'label' => Lang::t('the beginning'),
                'clause' => '1=1',
            ], // stupid but generic
        ];

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_comments');

        $since_raw = $_GET['since'] ?? null;
        // $_GET values are always strings (or arrays, for foo[]=... params) --
        // never a real PHP int/float/bool -- so only the string-emptiness
        // checks are reachable here.
        $since_present = is_string($since_raw) && $since_raw !== '' && $since_raw !== '0';
        if ($since_present) {
            $since = intval($since_raw);
        } else {
            $since = 4;
        }

        // on which field sorting
        //
        $sort_by_value = 'date';
        // if the form was submitted, it overloads default behaviour
        if (isset($_GET['sort_by']) and is_string($_GET['sort_by']) and isset($sort_by[$_GET['sort_by']])) {
            $sort_by_value = $_GET['sort_by'];
        }

        // order to sort
        //
        $sort_order_value = 'DESC';
        // if the form was submitted, it overloads default behaviour
        if (isset($_GET['sort_order']) and is_string($_GET['sort_order']) and isset($sort_order[$_GET['sort_order']])) {
            $sort_order_value = $_GET['sort_order'];
        }

        // number of items to display
        //
        $items_number_value = $comments_page_nb_comments;
        if (isset($_GET['items_number'])) {
            $items_number_value = $_GET['items_number'];
        }
        if (! is_numeric($items_number_value) and $items_number_value !== 'all') {
            $items_number_value = 10;
        }
        // after the checks above, items_number_value is guaranteed to be
        // either numeric or the literal string 'all'.
        $selected_items_number = is_numeric($items_number_value) ? $items_number_value : 'all';

        $whereClauses = [];

        // which category to filter on ?
        $cat_param = $_GET['cat'] ?? null;
        if (isset($_GET['cat']) and ! (is_numeric($cat_param) and (int) $cat_param === 0)) {
            new \Piwigo\Validation\InputValidator()
                ->validate('cat', $_GET, false, ValidationPattern::ID);

            $cat_id = $_GET['cat'];
            $cat_id = is_string($cat_id) ? $cat_id : '0';

            $category_ids = self::categoryService($conn)->getSubcatIds([$cat_id]);
            if ($category_ids === []) {
                $category_ids = [-1];
            }

            $whereClauses[] =
              'category_id IN (' . implode(',', $category_ids) . ')';
        }

        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $username_field = $user_fields['username'];
        $email_field = $user_fields['email'];
        $id_field = $user_fields['id'];

        // search a particular author
        $author_raw = $_GET['author'] ?? null;
        // $_GET values are always strings (or arrays) -- see $since_present's
        // own comment above.
        if (is_string($author_raw) && $author_raw !== '' && $author_raw !== '0') {
            $author_search = $author_raw;
            $whereClauses[] =
              '(u.' . $username_field . ' = \'' . $author_search . '\' OR author = \'' . $author_search . '\')';
        }

        // search a specific comment (if you're coming directly from an
        // admin notification email)
        $comment_id_raw = $_GET['comment_id'] ?? null;
        // $_GET values are always strings (or arrays) -- see $since_present's
        // own comment above.
        if (is_string($comment_id_raw) && $comment_id_raw !== '' && $comment_id_raw !== '0') {
            new \Piwigo\Validation\InputValidator()
                ->validate('comment_id', $_GET, false, ValidationPattern::ID);
            // check_input_parameter() validated this against
            // ValidationPattern::ID (/^\d+$/) above -- it would have called
            // fatal_error() otherwise.
            assert(is_numeric($_GET['comment_id']));
            $get_comment_id = $_GET['comment_id'];

            // currently, the $_GET['comment_id'] is only used by admins
            // from email for management purpose (validate/delete)
            if (! \Piwigo\Auth\AccessControl::isAdmin()) {
                $request_uri = $_SERVER['REQUEST_URI'] ?? '';
                $request_uri = is_string($request_uri) ? $request_uri : '';
                $login_url =
                  $this->urlService->getRootUrl() . 'identification.php?redirect='
                  . urlencode(urlencode($request_uri))
                ;
                $this->redirectService->redirect($login_url);
            }

            $whereClauses[] = 'com.id = ' . $get_comment_id;
        }

        // search a substring among comments content
        $keyword_raw = $_GET['keyword'] ?? null;
        // $_GET values are always strings (or arrays) -- see $since_present's
        // own comment above.
        if (is_string($keyword_raw) && $keyword_raw !== '' && $keyword_raw !== '0') {
            $keyword_search = $keyword_raw;
            $keywords = preg_split('/[\s,;]+/', $keyword_search);
            // the pattern above is a hardcoded, always-valid regex
            assert($keywords !== false);
            $whereClauses[] =
              '(' .
              implode(
                  ' AND ',
                  array_map(
                      fn ($s): string => "content LIKE '%{$s}%'",
                      $keywords
                  )
              ) .
              ')';
        }

        $whereClauses[] = $since_options[$since]['clause'];

        // which status to filter on ?
        if (! \Piwigo\Auth\AccessControl::isAdmin()) {
            $whereClauses[] = 'validated=1';
        }

        $whereClauses[] = self::permissionService($conn)->getSqlConditionFandF([
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'ic.image_id',
        ], '', true);

        // +-----------------------------------------------------------+
        // |                   comments management                     |
        // +-----------------------------------------------------------+

        $comment_id = null;
        $action = null;
        $edit_comment = null;

        $commentService = new CommentService(new CommentRepository($conn), new EphemeralKeyService(), new MailService(), new HtmlService(), $this->urlService);

        $actions = ['delete', 'validate', 'edit'];
        foreach ($actions as $loop_action) {
            if (isset($_GET[$loop_action])) {
                $action = $loop_action;
                new \Piwigo\Validation\InputValidator()
                    ->validate($action, $_GET, false, ValidationPattern::ID);
                // check_input_parameter() validated this against
                // ValidationPattern::ID (/^\d+$/) above -- it would have
                // called fatal_error() otherwise.
                $action_id_raw = $_GET[$action] ?? null;
                assert(is_numeric($action_id_raw));
                $comment_id = (int) $action_id_raw;
                break;
            }
        }

        if (isset($action) and $comment_id !== null) {
            $comment_author_id = $commentService->getCommentAuthorId($comment_id);
            // die_on_error defaults to true, so false is unreachable here
            assert($comment_author_id !== false);

            if (\Piwigo\Auth\AccessControl::canManageComment($action, $comment_author_id)) {
                $perform_redirect = false;

                if ($action === 'delete') {
                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(new HtmlService(), $this->redirectService);
                    $commentService->deleteComment($comment_id);
                    $perform_redirect = true;
                }

                if ($action === 'validate') {
                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(new HtmlService(), $this->redirectService);
                    $commentService->validateComment($comment_id);
                    $perform_redirect = true;
                }

                if ($action === 'edit') {
                    $content_raw = $_POST['content'] ?? null;
                    // $_POST values are always strings (or arrays) -- see
                    // $since_present's own comment above.
                    if (is_string($content_raw) && $content_raw !== '' && $content_raw !== '0') {
                        new \Piwigo\Csrf\CsrfService()
                            ->checkOrFail(new HtmlService(), $this->redirectService);
                        $post_key = $_POST['key'] ?? null;
                        if (! is_string($post_key)) {
                            $post_key = '';
                        }
                        $comment_action = $commentService->updateComment(
                            [
                                'comment_id' => $_GET['edit'],
                                'image_id' => $_POST['image_id'],
                                'content' => $_POST['content'],
                                'website_url' => @$_POST['website_url'],
                            ],
                            $post_key
                        );

                        switch ($comment_action) {
                            case 'moderate':
                                if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = Lang::t('An administrator must authorize your comment before it is visible.');
                                // no break
                            case 'validate':
                                if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = Lang::t('Your comment has been registered');
                                $perform_redirect = true;
                                break;
                            case 'reject':
                                if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                                    $_SESSION['page_errors'] = [];
                                }
                                $_SESSION['page_errors'][] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
                                break;
                            default:
                                trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                        }
                    }

                    $edit_comment = $_GET['edit'];
                }

                if ($perform_redirect) {
                    $this->redirectService->redirect($url_self);
                }
            }
        }

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.

        // +---------------------------------------------------------------+
        // |                    page header and options                    |
        // +---------------------------------------------------------------+

        $title = Lang::t('User comments');
        \Piwigo\Core\PageState::current()->setBodyId('theCommentsPage');

        $template->set_filenames([
            'comments' => 'comments.tpl',
            'comment_list' => 'comment_list.tpl',
        ]);
        $keyword_param_raw = $_GET['keyword'] ?? null;
        $keyword_param = is_string($keyword_param_raw) ? $keyword_param_raw : null;
        $author_param_raw = $_GET['author'] ?? null;
        $author_param = is_string($author_param_raw) ? $author_param_raw : null;

        $template->assign(
            [
                'F_ACTION' => $this->urlService->getRootUrl() . 'comments.php',
                'F_KEYWORD' => $keyword_param !== null ? htmlspecialchars(stripslashes($keyword_param)) : '',
                'F_AUTHOR' => $author_param !== null ? htmlspecialchars(stripslashes($author_param)) : '',
            ]
        );

        // +---------------------------------------------------------------+
        // |                      form construction                        |
        // +---------------------------------------------------------------+

        // Search in a particular category
        $blockname = 'categories';

        $query = '
SELECT id, name, uppercats, global_rank
  FROM ' . Tables::categories() . '
' . self::permissionService($conn)->getSqlConditionFandF([
            'forbidden_categories' => 'id',
            'visible_categories' => 'id',
        ], 'WHERE') . '
;';
        self::categoryService($conn)
            ->displaySelectCatWrapper($query, [@$_GET['cat']], $blockname, new HtmlService(), $template, true);

        // Filter on recent comments...
        $tpl_var = [];
        foreach ($since_options as $id => $option) {
            $tpl_var[$id] = $option['label'];
        }
        $template->assign('since_options', $tpl_var);
        $template->assign('since_options_selected', $since);

        // Sort by
        $template->assign('sort_by_options', $sort_by);
        $template->assign('sort_by_options_selected', $sort_by_value);

        // Sorting order
        $template->assign('sort_order_options', $sort_order);
        $template->assign('sort_order_options_selected', $sort_order_value);

        // Number of items
        $blockname = 'items_number_option';
        $tpl_var = [];
        foreach ($items_number as $option) {
            $tpl_var[$option] = is_numeric($option) ? $option : Lang::t($option);
        }
        $template->assign('item_number_options', $tpl_var);
        $template->assign('item_number_options_selected', $selected_items_number);

        // +---------------------------------------------------------------+
        // |                        navigation bar                         |
        // +---------------------------------------------------------------+

        if (isset($_GET['start']) and is_scalar($_GET['start'])) {
            $start = intval($_GET['start']);
        } else {
            $start = 0;
        }

        // +---------------------------------------------------------------+
        // |                     last comments display                     |
        // +---------------------------------------------------------------+

        $comments = [];
        $element_ids = [];
        $category_ids = [];

        $where_clauses = $whereClauses;

        // ANY_VALUE(ic.category_id): category_id comes from the JOINed
        // image_category table, not functionally dependent on the
        // GROUP BY column (comment_id) -- Piwigo\Db\DbConnection
        // doesn't strip ONLY_FULL_GROUP_BY the way the legacy mysqli
        // connection did (same class of issue as
        // Ws\PwgComments::getCommentsDateRange()'s own ANY_VALUE(author)
        // fix), so this query (already GROUP BY comment_id, unchanged
        // from the original) needs an explicit "any value is fine, same
        // as the old driver's own permissive default" opt-out to keep
        // selecting exactly one arbitrary category per comment, matching
        // the original grouping/row-count exactly rather than splitting
        // groups by adding category_id to GROUP BY.
        $query = '
SELECT SQL_CALC_FOUND_ROWS com.id AS comment_id,
   com.image_id,
   ANY_VALUE(ic.category_id) AS category_id,
   com.author,
   com.author_id,
   u.' . $email_field . ' AS user_email,
   com.email,
   com.date,
   com.website_url,
   com.content,
   com.validated
  FROM ' . Tables::imageCategory() . ' AS ic
INNER JOIN ' . Tables::comments() . ' AS com
ON ic.image_id = com.image_id
LEFT JOIN ' . Tables::users() . ' As u
ON u.' . $id_field . ' = com.author_id
  WHERE ' . implode('
AND ', $where_clauses) . '
  GROUP BY comment_id
  ORDER BY ' . $sort_by_value . ' ' . $sort_order_value . ', comment_id ' . $sort_order_value;
        if ($selected_items_number !== 'all') {
            $query .= '
  LIMIT ' . $selected_items_number . ' OFFSET ' . $start;
        }
        $query .= '
;';
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $comments[] = $row;
            $row_image_id = $row['image_id'];
            $row_category_id = $row['category_id'];
            // image_category.image_id / .category_id are both NOT NULL
            // columns.
            assert(is_scalar($row_image_id) && is_scalar($row_category_id));
            $element_ids[] = (string) $row_image_id;
            $category_ids[] = (string) $row_category_id;
        }
        // FOUND_ROWS() reflects the immediately-preceding query on the
        // SAME connection/session -- both share $conn, no intervening
        // query in between.
        $counter_raw = $conn->fetchOne('SELECT FOUND_ROWS()');
        $counter = is_numeric($counter_raw) ? (int) $counter_raw : 0;

        $url = $urlService->getRootUrl() . 'comments.php'
          . $urlService->getQueryStringDiff(['start', 'edit', 'delete', 'validate', 'pwg_token']);

        // when 'all' items are shown there is no real page size;
        // PHP_INT_MAX makes create_navigation_bar's own "more than one
        // page" check always false, so no pagination controls are
        // rendered (matching the 'all' UX intent).
        $items_number_for_navbar = is_numeric($selected_items_number) ? (int) $selected_items_number : PHP_INT_MAX;

        $navbar = new \Piwigo\Core\PaginationService()
            ->createNavigationBar($url, $counter, $start, $items_number_for_navbar);

        $template->assign('navbar', $navbar);

        if (count($comments) > 0) {
            // retrieving element informations
            $elements = array_map(
                static fn (\Piwigo\Image\Projection\Image $image): array => $image->toArray(),
                new ImageRepository($conn)
                    ->findByIds($element_ids)
            );

            // retrieving category informations
            $query = 'SELECT id, name, permalink, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')';
            $categories = array_column($conn->fetchAllAssociative($query), null, 'id');

            foreach ($comments as $comment) {
                $image_id_raw = $comment['image_id'];
                // comments.image_id / image_category.image_id are both
                // NOT NULL columns.
                assert(is_scalar($image_id_raw));
                $image_id = (string) $image_id_raw;

                $category_id_raw = $comment['category_id'];
                // image_category.category_id is a NOT NULL column
                assert(is_scalar($category_id_raw));
                $category_id = (string) $category_id_raw;

                $element_name = $elements[$image_id]['name'] ?? null;
                if (is_string($element_name) && $element_name !== '' && $element_name !== '0') {
                    $name = $element_name;
                } else {
                    $name = \Piwigo\Core\StringHelper::getNameFromFile($elements[$image_id]['file']);
                }

                // source of the thumbnail picture
                $src_image = new SrcImage($elements[$image_id]);

                // link to the full size picture
                $url = $urlService->makePictureUrl(
                    [
                        'category' => $categories[$category_id],
                        'image_id' => $image_id,
                        'image_file' => $elements[$image_id]['file'],
                    ]
                );

                $email = null;
                $user_email = $comment['user_email'] ?? null;
                $comment_email = $comment['email'] ?? null;
                if (is_string($user_email) && $user_email !== '' && $user_email !== '0') {
                    $email = $user_email;
                } elseif (is_string($comment_email) && $comment_email !== '' && $comment_email !== '0') {
                    $email = $comment_email;
                }

                // comments.date is nullable now (Comment domain Stage 1a
                // dropped its 1970-01-01 sentinel default) -- every real
                // insert still sets it explicitly, but formatDate()'s own
                // `false` "no date" sentinel is the correct fallback,
                // matching PwgComments::getList()'s own identical guard
                // for this same column.
                $date = is_string($comment['date']) ? $comment['date'] : false;

                $author_id = $comment['author_id'];
                // comments.author_id is nullable in schema; a NULL
                // author can never match a real user id, so treat it as
                // unowned rather than casting blindly.
                $author_id = is_numeric($author_id) ? (int) $author_id : -1;

                $tpl_comment = [
                    'ID' => $comment['comment_id'],
                    'U_PICTURE' => $url,
                    'src_image' => $src_image,
                    'ALT' => $name,
                    'AUTHOR' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_author', $comment['author']),
                    'WEBSITE_URL' => $comment['website_url'],
                    'DATE' => \Piwigo\Core\DateHelper::formatDate($date, ['day_name', 'day', 'month', 'year', 'time']),
                    'CONTENT' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_content', $comment['content']),
                ];

                if (\Piwigo\Auth\AccessControl::isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }

                if (\Piwigo\Auth\AccessControl::canManageComment('delete', $author_id)) {
                    $tpl_comment['U_DELETE'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'delete' => $comment['comment_id'],
                            'pwg_token' => new \Piwigo\Csrf\CsrfService()
                                ->getToken(),
                        ]
                    );
                }

                if (\Piwigo\Auth\AccessControl::canManageComment('edit', $author_id)) {
                    $tpl_comment['U_EDIT'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'edit' => $comment['comment_id'],
                        ]
                    );

                    $comment_id_str = is_scalar($comment['comment_id']) ? (string) $comment['comment_id'] : '';
                    if ($edit_comment !== null and is_numeric($edit_comment) and $comment_id_str === $edit_comment) {
                        $tpl_comment['IN_EDIT'] = true;
                        $key = new \Piwigo\Auth\EphemeralKeyService()
                            ->generate(2, $image_id);
                        $tpl_comment['KEY'] = $key;
                        $tpl_comment['IMAGE_ID'] = $image_id;
                        $tpl_comment['CONTENT'] = $comment['content'];
                        $tpl_comment['PWG_TOKEN'] = new \Piwigo\Csrf\CsrfService()->getToken();
                        $tpl_comment['U_CANCEL'] = $url_self;
                    }
                }

                if (\Piwigo\Auth\AccessControl::canManageComment('validate', $author_id)) {
                    if (! SqlDialect::getBoolean($comment['validated'])) {
                        $tpl_comment['U_VALIDATE'] = $urlService->addUrlParams(
                            $url_self,
                            [
                                'validate' => $comment['comment_id'],
                                'pwg_token' => new \Piwigo\Csrf\CsrfService()
                                    ->getToken(),
                            ]
                        );
                    }
                }
                $template->append('comments', $tpl_comment);
            }
        }

        $derivative_params = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_comments_derivative_params', ImageStdParams::get_by_type(ImageStdParams::THUMB));
        $template->assign('comment_derivative_params', $derivative_params);

        // include menubar
        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theCommentsPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($urlService);
        }

        // +---------------------------------------------------------------+
        // |                      html code display                        |
        // +---------------------------------------------------------------+
        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_comments');
        new HtmlService()
            ->flushPageMessages();
        if (count($comments) > 0) {
            $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
        }
        $template->parse('comments', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
