<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\Event\GetCommentsDerivativeParams;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
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

    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    private static function categoryService(): CategoryService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
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
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
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

        $commentsRequest = Request\CommentsRequest::fromGlobals($comments_page_nb_comments);

        $since = $commentsRequest->since !== null ? intval($commentsRequest->since) : 4;

        // on which field sorting
        $sort_by_value = $commentsRequest->sortBy;

        // order to sort
        $sort_order_value = $commentsRequest->sortOrder;

        // number of items to display
        $selected_items_number = $commentsRequest->itemsNumber;

        // SQL-modernization audit: $whereClauses is now list<SqlCondition>,
        // not list<string> -- the author/keyword filters below used to
        // splice raw, unvalidated $_GET content directly into the query
        // (manual '...\'' . $x . '\'...' quote-wrapping, no escaping or
        // binding at all) -- a real, live, guest-reachable SQL injection
        // via comments.php's own `author`/`keyword` query params
        // (`CommentsRequest::fromArrays()` applies no ValidationPattern to
        // either field, unlike comment_id/cat/the action fields). Fixed
        // here as bound parameters, not just converted for style.
        $whereClauses = [];

        // which category to filter on ?
        $cat_id = $commentsRequest->catId;
        if ($cat_id !== null) {
            $category_ids = self::categoryService()->getSubcatIds([$cat_id]);
            if ($category_ids === []) {
                $category_ids = [-1];
            }

            $whereClauses[] = new SqlCondition(
                'category_id IN (:categoryIds)',
                [
                    'categoryIds' => $category_ids,
                ],
                [
                    'categoryIds' => ArrayParameterType::INTEGER,
                ],
            );
        }

        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $username_field = $user_fields['username'];
        $email_field = $user_fields['email'];
        $id_field = $user_fields['id'];

        // search a particular author
        $author_filter = $commentsRequest->authorFilter;
        if ($author_filter !== null) {
            $author_search = $author_filter;
            $whereClauses[] = new SqlCondition(
                '(u.' . $username_field . ' = :authorSearchUsername OR author = :authorSearchAuthor)',
                [
                    'authorSearchUsername' => $author_search,
                    'authorSearchAuthor' => $author_search,
                ],
                [
                    'authorSearchUsername' => ParameterType::STRING,
                    'authorSearchAuthor' => ParameterType::STRING,
                ],
            );
        }

        // search a specific comment (if you're coming directly from an
        // admin notification email)
        $get_comment_id = $commentsRequest->commentId;
        if ($get_comment_id !== null) {
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

            $whereClauses[] = new SqlCondition(
                'com.id = :commentId',
                [
                    'commentId' => $get_comment_id,
                ],
                [
                    'commentId' => ParameterType::INTEGER,
                ],
            );
        }

        // search a substring among comments content
        $keyword_filter = $commentsRequest->keywordFilter;
        if ($keyword_filter !== null) {
            $keyword_search = $keyword_filter;
            $keywords = preg_split('/[\s,;]+/', $keyword_search);
            // the pattern above is a hardcoded, always-valid regex
            assert($keywords !== false);

            $keywordParts = [];
            $keywordParams = [];
            $keywordTypes = [];
            foreach ($keywords as $i => $keyword) {
                $placeholder = 'keyword' . $i;
                $keywordParts[] = 'content LIKE :' . $placeholder;
                // The %...% wildcard wrap is part of the bound VALUE, not
                // the SQL text -- matches the original's own unescaped
                // wildcard behavior for a literal %/_ inside the search
                // term itself (still interpreted as a LIKE wildcard, same
                // as before), while no longer allowing the term to break
                // out of the string literal entirely.
                $keywordParams[$placeholder] = '%' . $keyword . '%';
                $keywordTypes[$placeholder] = ParameterType::STRING;
            }

            $whereClauses[] = new SqlCondition('(' . implode(' AND ', $keywordParts) . ')', $keywordParams, $keywordTypes);
        }

        $whereClauses[] = new SqlCondition($since_options[$since]['clause']);

        // which status to filter on ?
        if (! \Piwigo\Auth\AccessControl::isAdmin()) {
            $whereClauses[] = new SqlCondition('validated=1');
        }

        $whereClauses[] = self::permissionService()->getSqlConditionFandFAsCondition([
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'ic.image_id',
        ], true);

        // +-----------------------------------------------------------+
        // |                   comments management                     |
        // +-----------------------------------------------------------+

        $action = $commentsRequest->action;
        $comment_id = $commentsRequest->actionCommentId;
        $edit_comment = null;

        $commentService = new CommentService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Comment\CommentEntity::class), new EphemeralKeyService(), \Piwigo\Bootstrap\PresentationAccessor::mailService(), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->urlService);

        if (isset($action) and $comment_id !== null) {
            $commentIdVo = CommentId::from($comment_id);
            $comment_author_id = $commentService->getCommentAuthorId($commentIdVo);
            // die_on_error defaults to true, so false is unreachable here
            assert($comment_author_id !== false);

            if (\Piwigo\Auth\AccessControl::canManageComment($action, $comment_author_id)) {
                $perform_redirect = false;

                if ($action === 'delete') {
                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
                    $commentService->deleteComment($commentIdVo);
                    $perform_redirect = true;
                }

                if ($action === 'validate') {
                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
                    $commentService->validateComment($commentIdVo);
                    $perform_redirect = true;
                }

                if ($action === 'edit') {
                    $content = $commentsRequest->content;
                    if ($content !== null) {
                        new \Piwigo\Csrf\CsrfService()
                            ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
                        $comment_action = $commentService->updateComment(
                            [
                                'comment_id' => $comment_id,
                                'image_id' => $commentsRequest->imageId,
                                'content' => $content,
                                'website_url' => $commentsRequest->websiteUrl,
                            ],
                            $commentsRequest->key
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

                    $edit_comment = $comment_id;
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
        $keyword_param = $commentsRequest->keywordDisplay;
        $author_param = $commentsRequest->authorDisplay;

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

        $categoriesTable = Tables::categories();
        $categoryCondition = self::permissionService()->getSqlConditionFandFAsCondition([
            'forbidden_categories' => 'id',
            'visible_categories' => 'id',
        ]);
        $categoryConditionSql = $categoryCondition->isEmpty() ? '' : 'WHERE ' . $categoryCondition->sql;
        $query = <<<SQL
            SELECT id, name, uppercats, global_rank
            FROM {$categoriesTable}
            {$categoryConditionSql}
            SQL;
        self::categoryService()
            ->displaySelectCatWrapper($query, [$commentsRequest->catDisplay], $blockname, \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $template, true, $categoryCondition->parameters, $categoryCondition->types);

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

        $start = $commentsRequest->start;

        // +---------------------------------------------------------------+
        // |                     last comments display                     |
        // +---------------------------------------------------------------+

        $comments = [];
        $element_ids = [];
        $category_ids = [];

        $where_clauses = $whereClauses;

        $paginated_comments = $commentService->getAllCommentsWithConditions(
            $where_clauses,
            $id_field,
            $email_field,
            $sort_by_value,
            $sort_order_value,
            $selected_items_number,
            $start
        );
        foreach ($paginated_comments->rows as $row) {
            $comments[] = $row;
            $row_image_id = $row['image_id'];
            $row_category_id = $row['category_id'];
            // image_category.image_id / .category_id are both NOT NULL
            // columns.
            assert(is_scalar($row_image_id) && is_scalar($row_category_id));
            $element_ids[] = (string) $row_image_id;
            $category_ids[] = (string) $row_category_id;
        }
        $counter = $paginated_comments->total ?? 0;

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
                \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                    ->findByIds($element_ids)
            );

            // retrieving category informations
            $categories = array_column(
                self::categoryService()->getCategoriesByIds(array_map(intval(...), $category_ids)),
                null,
                'id'
            );

            foreach ($comments as $comment) {
                $image_id_raw = $comment['image_id'];
                // comments.image_id / image_category.image_id are both
                // NOT NULL columns.
                assert(is_scalar($image_id_raw));
                $image_id = (string) $image_id_raw;
                // $elements is keyed by ImageRepository::findByIds()'s own
                // real int image ids, not the string $image_id used
                // everywhere else in this loop (URL params, template vars).
                $image_id_int = (int) $image_id;

                $category_id_raw = $comment['category_id'];
                // image_category.category_id is a NOT NULL column
                assert(is_scalar($category_id_raw));
                $category_id = (string) $category_id_raw;

                $element_name = $elements[$image_id_int]['name'] ?? null;
                if (is_string($element_name) && $element_name !== '' && $element_name !== '0') {
                    $name = $element_name;
                } else {
                    $name = \Piwigo\Core\StringHelper::getNameFromFile($elements[$image_id_int]['file']);
                }

                // source of the thumbnail picture
                $src_image = new SrcImage($elements[$image_id_int]);

                // link to the full size picture
                $url = $urlService->makePictureUrl(
                    [
                        'category' => $categories[$category_id],
                        'image_id' => $image_id,
                        'image_file' => $elements[$image_id_int]['file'],
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

                $authorEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderCommentAuthor(is_string($comment['author']) ? $comment['author'] : ''));

                $contentEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderCommentContent(is_string($comment['content']) ? $comment['content'] : ''));

                $tpl_comment = [
                    'ID' => $comment['comment_id'],
                    'U_PICTURE' => $url,
                    'src_image' => $src_image,
                    'ALT' => $name,
                    'AUTHOR' => $authorEvent->commentAuthor,
                    'WEBSITE_URL' => $comment['website_url'],
                    'DATE' => \Piwigo\Core\DateHelper::formatDate($date, ['day_name', 'day', 'month', 'year', 'time']),
                    'CONTENT' => $contentEvent->commentContent,
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
                    if ($edit_comment !== null and $comment_id_str === (string) $edit_comment) {
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

        $derivative_params = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new GetCommentsDerivativeParams(ImageStdParams::get_by_type(ImageStdParams::THUMB)))->params;
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
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        if (count($comments) > 0) {
            $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
        }
        $template->parse('comments', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
