<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Event\CommentsPageRendered;
use Piwigo\Controller\Projection\CommentsView;
use Piwigo\Controller\Request\CommentsRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\Db\SqlDialect;
use Piwigo\Html\Event\RenderCommentContent;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Picture\Event\RenderCommentAuthor;
use Piwigo\Picture\Projection\CommentListView;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Sort\CommentSortField;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The front-end "all comments" listing + per-comment moderation actions
 * (delete/validate/edit). The "comments management" block
 * (delete/validate/edit, including its own check_pwg_token()/redirect()
 * calls) all happens before any rendering starts.
 */
final readonly class CommentsController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private LayoutState $layoutState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private CategoryService $categoryService,
        private HtmlService $htmlService,
        private MailerInterface $mailer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private Renderer $renderer,
    ) {}

    /**
     * Same recipe as Controller\PictureController's own commentService()
     * resolver -- unlike that one, $urlService here is already a
     * constructor property (this controller has no per-call-varying URL
     * builder), so this needs no param.
     */
    private function commentService(): CommentService
    {
        return new CommentService($this->lang, $this->entityManager->getRepository(CommentEntity::class), new EphemeralKeyService($this->currentConfig), $this->mailer, $this->htmlService, $this->urlService, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentConfig, new AccessLevelChecker($this->currentUser, $this->currentConfig));
    }

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->activateComments) {
            $this->htmlService
                ->pageNotFound($this->redirectService, null);
        }

        $this->accessControl->checkStatus(AccessLevel::Guest);

        $url_self = $this->urlService->getRootUrl() . 'comments.php'
          . $this->urlService->getQueryStringDiff(['delete', 'edit', 'validate', 'pwg_token']);

        $sort_order = [
            'DESC' => $this->lang->t('descending'),
            'ASC' => $this->lang->t('ascending'),
        ];

        // sort_by : database fields proposed for sorting comments list
        $sort_by = [
            'date' => $this->lang->t('comment date'),
            'image_id' => $this->lang->t('photo'),
        ];

        // items_number : list of number of items to display per page
        $items_number = [5, 10, 20, 50, 'all'];

        // \Piwigo\Config\CurrentConfig::commentsPageNbComments() is a plain int setting (see
        // include/config_default.inc.php); $conf itself is only known as
        // array<string, mixed>, so narrow with a fallback matching the
        // shipped default rather than trust the shape blindly.
        $comments_page_nb_comments = $this->currentConfig->commentsPageNbComments;

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

        // since when display comments ? -- findAllWithConditions() is
        // DQL-backed, so 'clause' is a real SqlCondition (DQL property
        // path `com.date`, SqlDialect::getRecentPeriodDqlExpression()),
        // not a raw-SQL string fragment. DQL's own CURRENT_DATE()
        // function, or -- in test mode -- a frozen PIWIGO_TEST_NOW-anchored
        // bound parameter: the same Env::testModeIsActive() substitution
        // SqlDialect::getRecentPeriodExpression()'s own default-$date
        // branch already establishes for its raw-SQL counterpart.
        $sinceCurrentDate = 'CURRENT_DATE()';
        $sinceParameters = [];
        $sinceTypes = [];
        if (Env::testModeIsActive()) {
            $sinceCurrentDate = ':sinceCurrentDate';
            $sinceParameters['sinceCurrentDate'] = Env::now()->format('Y-m-d');
            $sinceTypes['sinceCurrentDate'] = ParameterType::STRING;
        }

        $since_options = [
            1 => [
                'label' => $this->lang->t('today'),
                'clause' => SqlCondition::fromRawSql('com.date > ' . SqlDialect::getRecentPeriodDqlExpression(1, $sinceCurrentDate), $sinceParameters, $sinceTypes),
            ],
            2 => [
                'label' => $this->lang->t('last %d days', 7),
                'clause' => SqlCondition::fromRawSql('com.date > ' . SqlDialect::getRecentPeriodDqlExpression(7, $sinceCurrentDate), $sinceParameters, $sinceTypes),
            ],
            3 => [
                'label' => $this->lang->t('last %d days', 30),
                'clause' => SqlCondition::fromRawSql('com.date > ' . SqlDialect::getRecentPeriodDqlExpression(30, $sinceCurrentDate), $sinceParameters, $sinceTypes),
            ],
            4 => [
                'label' => $this->lang->t('the beginning'),
                // "since the beginning" is no date restriction at all; the
                // empty fragment is dropped when the clauses are combined.
                'clause' => SqlCondition::fromRawSql(''),
            ],
        ];

        $commentsRequest = CommentsRequest::fromGlobals($comments_page_nb_comments, $this->inputValidator);

        $since = $commentsRequest->since !== null ? intval($commentsRequest->since) : 4;

        // on which field sorting -- $sort_by_value stays the raw token for
        // the template's own selected-option rendering; $sortByField is
        // the typed vocabulary getAllCommentsWithConditions() takes.
        // CommentsRequest::fromArrays() already allowlist-validates
        // $sort_by_value against this exact 2-token vocabulary, so
        // fromToken() never actually falls back to Date here in
        // practice -- the fallback exists for the type checker, not a
        // real runtime path.
        $sort_by_value = $commentsRequest->sortBy;
        $sortByField = CommentSortField::fromToken($sort_by_value) ?? CommentSortField::Date;

        // order to sort
        $sort_order_value = $commentsRequest->sortOrder;

        // number of items to display
        $selected_items_number = $commentsRequest->itemsNumber;

        // $whereClauses is list<SqlCondition>, not list<string> -- the
        // author/keyword filters below used to
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
            $category_ids = $this->categoryService->getSubcatIds([$cat_id]);
            if ($category_ids === []) {
                $category_ids = [NoMatchSentinel::ID];
            }

            $whereClauses[] = SqlCondition::fromRawSql(
                'ic.category IN (:categoryIds)',
                [
                    'categoryIds' => $category_ids,
                ],
                [
                    'categoryIds' => ArrayParameterType::INTEGER,
                ],
            );
        }

        // search a particular author
        $author_filter = $commentsRequest->authorFilter;
        if ($author_filter !== null) {
            $author_search = $author_filter;
            $whereClauses[] = SqlCondition::fromRawSql(
                '(u.username = :authorSearchUsername OR com.author = :authorSearchAuthor)',
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
            if (! $this->accessControl->isAdmin()) {
                $request_uri = $_SERVER['REQUEST_URI'] ?? '';
                /**
                 * @psalm-suppress RedundantCondition
                 * @psalm-suppress TypeDoesNotContainType Psalm's $_SERVER
                 *   superglobal stub is typed more optimistically than
                 *   reality: REQUEST_URI is never guaranteed present or
                 *   string the way Psalm's stub assumes.
                 */
                $request_uri = is_string($request_uri) ? $request_uri : '';
                $login_url =
                  $this->urlService->getRootUrl() . 'identification.php?redirect='
                  . urlencode(urlencode($request_uri))
                ;
                $this->redirectService->redirect($login_url);
            }

            $whereClauses[] = SqlCondition::fromRawSql(
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
                $keywordParts[] = 'com.content LIKE :' . $placeholder;
                // The %...% wildcard wrap is part of the bound VALUE, not
                // the SQL text -- matches the original's own unescaped
                // wildcard behavior for a literal %/_ inside the search
                // term itself (still interpreted as a LIKE wildcard, same
                // as before), while no longer allowing the term to break
                // out of the string literal entirely.
                $keywordParams[$placeholder] = '%' . $keyword . '%';
                $keywordTypes[$placeholder] = ParameterType::STRING;
            }

            $whereClauses[] = SqlCondition::fromRawSql('(' . implode(' AND ', $keywordParts) . ')', $keywordParams, $keywordTypes);
        }

        $whereClauses[] = $since_options[$since]['clause'];

        // which status to filter on ?
        if (! $this->accessControl->isAdmin()) {
            // comments.validated
            // is a genuine `boolean` column on Postgres (not the
            // smallint-with-integer-range convention this codebase uses
            // elsewhere), so the bare `validated=1` literal that's valid
            // MySQL tinyint(1) input fails outright against Postgres
            // ("operator does not exist: boolean = integer"). Bound as a
            // real BOOLEAN parameter, matching every other real validated
            // comparison in CommentRepository.php (all `->setParameter(...,
            // true)`), rather than another raw literal.
            $whereClauses[] = SqlCondition::fromRawSql('com.validated = :validated', [
                'validated' => true,
            ], [
                'validated' => ParameterType::BOOLEAN,
            ]);
        }

        $commentsPermissionCriteria = $this->permissionService->getPermissionCriteria();
        $whereClauses[] = $commentsPermissionCriteria->forbiddenCategoriesCondition('ic.category');
        $whereClauses[] = $commentsPermissionCriteria->visibleCategoriesCondition('ic.category');
        $whereClauses[] = $commentsPermissionCriteria->visibleImagesCondition('ic.image');
        $whereClauses[] = $commentsPermissionCriteria->imageAccessCondition('ic.image');

        $action = $commentsRequest->action;
        $comment_id = $commentsRequest->actionCommentId;
        $edit_comment = null;

        $commentService = $this->commentService();

        if (isset($action) and $comment_id !== null) {
            $commentIdVo = CommentId::from($comment_id);
            $comment_author_id = $commentService->getCommentAuthorId($commentIdVo);
            // die_on_error defaults to true, so false is unreachable here
            assert($comment_author_id !== false);

            if ($this->accessControl->canManageComment($action, $comment_author_id)) {
                $perform_redirect = false;

                if ($action === 'delete') {
                    $this->csrfService
                        ->checkOrFail($this->htmlService, $this->redirectService);
                    $commentService->deleteComment($commentIdVo);
                    $perform_redirect = true;
                }

                if ($action === 'validate') {
                    $this->csrfService
                        ->checkOrFail($this->htmlService, $this->redirectService);
                    $commentService->validateComment($commentIdVo);
                    $perform_redirect = true;
                }

                if ($action === 'edit') {
                    $content = $commentsRequest->content;
                    if ($content !== null) {
                        $this->csrfService
                            ->checkOrFail($this->htmlService, $this->redirectService);
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
                                $_SESSION['page_infos'][] = $this->lang->t('An administrator must authorize your comment before it is visible.');
                                // no break
                            case 'validate':
                                if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                    $_SESSION['page_infos'] = [];
                                }
                                $_SESSION['page_infos'][] = $this->lang->t('Your comment has been registered');
                                $perform_redirect = true;
                                break;
                            case 'reject':
                                if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                                    $_SESSION['page_errors'] = [];
                                }
                                $_SESSION['page_errors'][] = $this->lang->t('Your comment has NOT been registered because it did not pass the validation rules');
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

        $title = $this->lang->t('User comments');
        $this->layoutState->setBodyId('theCommentsPage');

        $keyword_param = $commentsRequest->keywordDisplay;
        $author_param = $commentsRequest->authorDisplay;

        // Search in a particular category
        $categoriesOptions = $this->categoryService
            ->displaySelectByCondition($this->permissionService->getPermissionCriteria(), [$commentsRequest->catDisplay], $this->htmlService);

        // Filter on recent comments...
        $since_options_tpl = [];
        foreach ($since_options as $id => $option) {
            $since_options_tpl[$id] = $option['label'];
        }

        // Number of items
        $blockname = 'items_number_option';
        $item_number_options = [];
        foreach ($items_number as $option) {
            $item_number_options[$option] = is_numeric($option) ? $option : $this->lang->t($option);
        }

        $start = $commentsRequest->start;

        $comments = [];
        $element_ids = [];
        $category_ids = [];

        $where_clauses = $whereClauses;

        $paginated_comments = $commentService->getAllCommentsWithConditions(
            $where_clauses,
            $sortByField,
            $sort_order_value,
            $selected_items_number,
            $start
        );
        foreach ($paginated_comments->rows as $row) {
            $comments[] = $row;
            $element_ids[] = (string) $row->imageId;
            $category_ids[] = (string) $row->categoryId;
        }
        $counter = $paginated_comments->total ?? 0;

        $url = $urlService->getRootUrl() . 'comments.php'
          . $urlService->getQueryStringDiff(['start', 'edit', 'delete', 'validate', 'pwg_token']);

        // when 'all' items are shown there is no real page size;
        // PHP_INT_MAX makes create_navigation_bar's own "more than one
        // page" check always false, so no pagination controls are
        // rendered (matching the 'all' UX intent).
        $items_number_for_navbar = is_numeric($selected_items_number) ? (int) $selected_items_number : PHP_INT_MAX;

        $navbar = new PaginationService($this->currentConfig)
            ->createNavigationBar($url, $counter, $start, $items_number_for_navbar);

        $tpl_comments = [];
        if (count($comments) > 0) {
            // retrieving element informations
            $elements = array_map(
                static fn (Image $image): array => $image->toArray(),
                $this->entityManager->getRepository(ImageEntity::class)
                    ->findByIds($element_ids)
            );

            // retrieving category informations
            $categories = array_column(
                $this->categoryService->getCategoriesByIds(array_map(intval(...), $category_ids)),
                null,
                'id'
            );

            foreach ($comments as $comment) {
                $image_id = (string) $comment->imageId;
                // $elements is keyed by ImageRepository::findByIds()'s own
                // real int image ids, not the string $image_id used
                // everywhere else in this loop (URL params, template vars).
                $image_id_int = (int) $image_id;

                $category_id = (string) $comment->categoryId;

                $element_name = $elements[$image_id_int]['name'] ?? null;
                if (is_string($element_name) && $element_name !== '' && $element_name !== '0') {
                    $name = $element_name;
                } else {
                    $name = StringHelper::getNameFromFile($elements[$image_id_int]['file']);
                }

                // source of the thumbnail picture
                $src_image = new SrcImage(SrcImageInfo::fromRow($elements[$image_id_int]));

                // link to the full size picture
                /**
                 * @psalm-suppress InvalidArrayOffset $category_id is a
                 *   numeric category id kept as a string (see the cast
                 *   above); PHP canonicalises it back to an int array key
                 *   at runtime regardless of Psalm's own static string
                 *   typing here.
                 */
                $url = $urlService->makePictureUrl(
                    [
                        'category' => $categories[$category_id],
                        'image_id' => $image_id,
                        'image_file' => $elements[$image_id_int]['file'],
                    ]
                );

                $email = null;
                $user_email = $comment->userEmail;
                $comment_email = $comment->email;
                if (is_string($user_email) && $user_email !== '' && $user_email !== '0') {
                    $email = $user_email;
                } elseif (is_string($comment_email) && $comment_email !== '' && $comment_email !== '0') {
                    $email = $comment_email;
                }

                // comments.date is nullable -- every real
                // insert still sets it explicitly, but formatDate()'s own
                // `false` "no date" sentinel is the correct fallback,
                // matching Comments::getList()'s own identical guard
                // for this same column.
                $date = is_string($comment->date) ? $comment->date : false;

                $author_id = $comment->authorId;
                // comments.author_id is nullable in schema; a NULL
                // author can never match a real user id, so treat it as
                // unowned rather than casting blindly.
                $author_id = is_numeric($author_id) ? (int) $author_id : -1;

                $authorEvent = $this->eventDispatcher->dispatch(new RenderCommentAuthor(is_string($comment->author) ? $comment->author : ''));

                $contentEvent = $this->eventDispatcher->dispatch(new RenderCommentContent(is_string($comment->content) ? $comment->content : ''));

                $tpl_comment = [
                    'ID' => $comment->commentId,
                    'U_PICTURE' => $url,
                    'src_image' => $src_image,
                    'ALT' => $name,
                    'AUTHOR' => $authorEvent->commentAuthor,
                    'WEBSITE_URL' => $comment->websiteUrl,
                    'DATE' => DateHelper::formatDate($date, ['day_name', 'day', 'month', 'year', 'time']),
                    'CONTENT' => $contentEvent->commentContent,
                ];

                if ($this->accessControl->isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }

                if ($this->accessControl->canManageComment('delete', $author_id)) {
                    $tpl_comment['U_DELETE'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'delete' => $comment->commentId,
                            'pwg_token' => $this->csrfService
                                ->getToken(),
                        ]
                    );
                }

                if ($this->accessControl->canManageComment('edit', $author_id)) {
                    $tpl_comment['U_EDIT'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'edit' => $comment->commentId,
                        ]
                    );

                    $comment_id_str = (string) $comment->commentId;
                    if ($edit_comment !== null and $comment_id_str === (string) $edit_comment) {
                        $tpl_comment['IN_EDIT'] = true;
                        $key = new EphemeralKeyService($this->currentConfig)
                            ->generate(2, $image_id);
                        $tpl_comment['KEY'] = $key;
                        $tpl_comment['IMAGE_ID'] = $image_id;
                        $tpl_comment['CONTENT'] = $comment->content;
                        $tpl_comment['CSRF_TOKEN'] = $this->csrfService->getToken();
                        $tpl_comment['U_CANCEL'] = $url_self;
                    }
                }

                if ($this->accessControl->canManageComment('validate', $author_id)) {
                    if (! SqlDialect::getBoolean($comment->validated)) {
                        $tpl_comment['U_VALIDATE'] = $urlService->addUrlParams(
                            $url_self,
                            [
                                'validate' => $comment->commentId,
                                'pwg_token' => $this->csrfService
                                    ->getToken(),
                            ]
                        );
                    }
                }
                $tpl_comments[] = $tpl_comment;
            }
        }

        $derivative_params = $this->imageStdParams->getByType(ImageStdParams::THUMB);

        // include menubar
        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theCommentsPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager, $this->renderer);
        }

        new PageHeaderRenderer()
            ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatch(new CommentsPageRendered());
        $this->htmlService
            ->flushPageMessages();
        $commentList = count($comments) > 0
            ? $this->renderer->render(new CommentListView(comments: $tpl_comments, commentDerivativeParams: $derivative_params, rootUrl: $this->urlService->getRootUrl(), iconDir: $template->themeConf('icon_dir')))
            : null;

        $commentsView = new CommentsView(
            homeUrl: $this->urlService->makeIndexUrl(),
            fAction: $this->urlService->getRootUrl() . 'comments.php',
            fKeyword: $keyword_param ?? '',
            fAuthor: $author_param ?? '',
            sinceOptions: $since_options_tpl,
            sinceOptionsSelected: $since,
            sortByOptions: $sort_by,
            sortByOptionsSelected: $sort_by_value,
            sortOrderOptions: $sort_order,
            sortOrderOptionsSelected: $sort_order_value,
            itemNumberOptions: $item_number_options,
            itemNumberOptionsSelected: $selected_items_number,
            navbar: $navbar->toArray(),
            commentDerivativeParams: $derivative_params,
            categories: $categoriesOptions->options,
            categoriesSelected: $categoriesOptions->selected,
            commentList: $commentList,
        );

        PageTail::prepareContext();

        $html = $this->renderer->render($commentsView);
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }
}
