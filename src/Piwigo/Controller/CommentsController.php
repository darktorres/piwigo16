<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocBeginComments;
use Piwigo\Event\Location\LocEndComments;
use Piwigo\Event\Picture\GetCommentsDerivativeParams;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Page\PaginationService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the paginated comments list page (/comments).
 * Corresponds to the former comments.php entry-point.
 */
final readonly class CommentsController implements ControllerInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CommentRepository $commentRepository,
        private CommentService $commentService,
        private ImageRepository $imageRepository,
        private DateService $dateService,
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private RedirectResponder $redirectResponder,
        private EphemeralKeyService $ephemeralKeyService,
        private PaginationService $paginationService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!Config::activateComments()) {
            $this->htmlService->pageNotFound(null);
        }

        $this->permissionService->checkStatus(AccessLevel::Guest);

        $url_self   = $this->urlGenerator->comments() . $this->urlService->getQueryStringDiff(['delete', 'edit', 'validate', 'pwg_token']);
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

        $this->dispatcher->dispatch(new LocBeginComments());

        $get_since = StringUtil::inputInt('since', null, $_GET);
        $since = ($get_since !== null && $get_since !== 0) ? $get_since : 4;

        $sortBy = 'date';
        $get_sort_by = StringUtil::inputString('sort_by', null, $_GET);
        if ($get_sort_by !== null && isset($sort_by[$get_sort_by])) {
            $sortBy = $get_sort_by;
        }

        $sortOrder = 'DESC';
        $get_sort_order = StringUtil::inputString('sort_order', null, $_GET);
        if ($get_sort_order !== null && isset($sort_order[$get_sort_order])) {
            $sortOrder = $get_sort_order;
        }

        $itemsNumber = Config::commentsPageNbComments();
        $get_items_number = StringUtil::inputString('items_number', null, $_GET);
        if ($get_items_number !== null) {
            $itemsNumber = $get_items_number;
        }
        if (!is_numeric($itemsNumber) && $itemsNumber != 'all') {
            $itemsNumber = 10;
        }

        $whereClauses = [];

        $get_cat = StringUtil::inputInt('cat', null, $_GET);
        if ($get_cat !== null && 0 != $get_cat) {
            $this->inputValidator->check('cat', $_GET, false, ValidationPattern::ID);
            $category_ids = $this->categoryService->getSubcatIds([$get_cat]);
            if (empty($category_ids)) {
                $category_ids = [-1];
            }
            $whereClauses[] = 'category_id IN (' . implode(',', $category_ids) . ')';
        }

        $get_author = StringUtil::inputString('author', null, $_GET);
        if ($get_author !== null && $get_author !== '') {
            $whereClauses[] = '(u.' . Config::userFields()['username'] . ' = \'' . $get_author . '\' OR author = \'' . $get_author . '\')';
        }

        $get_comment_id_filter = StringUtil::inputInt('comment_id', null, $_GET);
        if ($get_comment_id_filter !== null && $get_comment_id_filter !== 0) {
            $this->inputValidator->check('comment_id', $_GET, false, ValidationPattern::ID);
            if (!$this->permissionService->isAdmin()) {
                $requestUriRaw = $_SERVER['REQUEST_URI'] ?? null;
                $requestUri = is_string($requestUriRaw) ? $requestUriRaw : '';
                $login_url  = $this->urlService->addUrlParams($this->urlGenerator->identification(), ['redirect' => urlencode($requestUri)]);
                $this->redirectResponder->redirect($login_url);
            }
            $whereClauses[] = 'com.id = ' . $get_comment_id_filter;
        }

        $get_keyword = StringUtil::inputString('keyword', null, $_GET);
        if ($get_keyword !== null && $get_keyword !== '') {
            $whereClauses[] = '(' . implode(' AND ', array_map(
                fn (string $s): string => "content LIKE '%$s%'",
                preg_split('/[\s,;]+/', $get_keyword) ?: []
            )) . ')';
        }

        $pageSince = in_array($since, [1, 2, 3, 4]) ? $since : 4;
        $whereClauses[] = $since_options[$pageSince]['clause'];

        if (!$this->permissionService->isAdmin()) {
            // `validated` is TINYINT(1) post-E2; the legacy 'true' coerces to 0,
            // i.e. "not validated", which was a bug — fixed to 1.
            $whereClauses[] = 'validated=1';
        }

        $permParams1 = [];
        $permTypes1  = [];
        [$permSql1, $permParams1, $permTypes1] = $this->permissionService->getSqlConditionFandF(
            ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'ic.image_id'],
            '',
            true
        );
        $whereClauses[] = $permSql1;

        // Comment actions
        $comment_id = 0;
        $action     = null;
        $edit_comment = null;

        foreach (['delete', 'validate', 'edit'] as $loop_action) {
            if (isset($_GET[$loop_action])) {
                $action = $loop_action;
                $this->inputValidator->check($action, $_GET, false, ValidationPattern::ID);
                $actionRaw = $_GET[$action] ?? null;
                $comment_id = is_numeric($actionRaw) ? (int) $actionRaw : 0;
                break;
            }
        }

        if (isset($action)) {
            $comment_author_id = $this->commentService->getCommentAuthorId($comment_id);
            if ($this->permissionService->canManageComment($action, $comment_author_id)) {
                $perform_redirect = false;
                if ('delete' == $action) {
                    $this->csrfService->check();
                    $this->commentService->deleteUserComment($comment_id);
                    $perform_redirect = true;
                }
                if ('validate' == $action) {
                    $this->csrfService->check();
                    $this->commentService->validateUserComment($comment_id);
                    $perform_redirect = true;
                }
                if ('edit' == $action) {
                    $post_content = StringUtil::inputString('content', null, $_POST);
                    if ($post_content !== null && $post_content !== '') {
                        $this->csrfService->check();
                        $comment_action = $this->commentService->updateUserComment(
                            ['comment_id' => $comment_id, 'image_id' => StringUtil::inputInt('image_id', null, $_POST), 'content' => $post_content, 'website_url' => StringUtil::inputString('website_url', null, $_POST)],
                            StringUtil::inputString('key', null, $_POST) ?? ''
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
                                throw new \LogicException('Invalid comment action: ' . $comment_action);
                        }
                    }
                    $edit_comment = $comment_id;
                }
                if ($perform_redirect) {
                    $this->redirectResponder->redirect($url_self);
                }
            }
        }

        $title = Lang::t('User comments');
        PageState::current()->bodyId = 'theCommentsPage';

        $tpl = TemplateRegistry::current();
        $tpl->assign([
            'F_ACTION'  => $this->urlGenerator->comments(),
            'F_KEYWORD' => ($get_keyword !== null && $get_keyword !== '') ? htmlspecialchars(stripslashes($get_keyword)) : '',
            'F_AUTHOR'  => ($get_author !== null && $get_author !== '') ? htmlspecialchars(stripslashes($get_author)) : '',
        ]);

        $blockname = 'categories';
        [$catPermSql, $catPermParams, $catPermTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'WHERE');
        $query = '
SELECT id, name, uppercats, global_rank
  FROM ' . Tables::categories() . '
' . $catPermSql . '
;';
        $this->categoryService->displaySelectCatWrapper($query, array_filter([$get_cat], fn (mixed $v): bool => $v !== null), $blockname, true, $catPermParams, $catPermTypes);

        $tpl_var = [];
        foreach ($since_options as $id => $option) {
            $tpl_var[$id] = $option['label'];
        }
        $tpl->assign('since_options', $tpl_var);
        $tpl->assign('since_options_selected', $pageSince);
        $tpl->assign('sort_by_options', $sort_by);
        $tpl->assign('sort_by_options_selected', $sortBy);
        $tpl->assign('sort_order_options', $sort_order);
        $tpl->assign('sort_order_options_selected', $sortOrder);

        $tpl_var = [];
        foreach ($items_number as $option) {
            $tpl_var[$option] = is_numeric($option) ? $option : Lang::t($option);
        }
        $tpl->assign('item_number_options', $tpl_var);
        $tpl->assign('item_number_options_selected', $itemsNumber);

        $start        = StringUtil::inputInt('start', 0, $_GET);
        $comments     = [];
        $element_ids  = [];
        $category_ids = [];

        $userEmailField = Config::userFields()['email'];
        $userIdField    = Config::userFields()['id'];
        $usersTable     = Tables::users();

        $counter = $this->commentRepository->countFilteredComments($whereClauses, $permParams1, $permTypes1, $usersTable, $userIdField);

        $pageItemsNumber = $itemsNumber;
        $rowLimit  = 'all' == $pageItemsNumber ? -1 : (int) $pageItemsNumber;
        $rowOffset = $start ?? 0;
        foreach ($this->commentRepository->findFilteredComments(
            $whereClauses,
            $permParams1,
            $permTypes1,
            $usersTable,
            $userIdField,
            $userEmailField,
            $sortBy,
            $sortOrder,
            $rowLimit,
            $rowOffset,
        ) as $row) {
            $comments[]     = $row;
            $element_ids[]  = $row['image_id'];
            $category_ids[] = $row['category_id'];
        }

        $url     = $this->urlGenerator->comments() . $this->urlService->getQueryStringDiff(['start', 'edit', 'delete', 'validate', 'pwg_token']);
        $navbar  = $this->paginationService->createNavigationBar(
            $url,
            $counter,
            $start ?? 0,
            (int) $pageItemsNumber,
            false
        );
        $tpl->assign('navbar', $navbar);

        if (count($comments) > 0) {
            $elementIdsInt  = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $element_ids);
            $categoryIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $category_ids);
            $elements   = array_column($this->imageRepository->findByIds($elementIdsInt), null, 'id');
            $categories = $this->categoryRepository->findNamePermalinkUppercatsByIds($categoryIdsInt);

            foreach ($comments as $comment) {
                /** @var array<string, float|int|string|null> $comment */
                $cImageId     = is_numeric($comment['image_id']) ? (int) $comment['image_id'] : 0;
                $cCategoryId  = is_numeric($comment['category_id']) ? (int) $comment['category_id'] : 0;
                $cId          = is_numeric($comment['comment_id']) ? (int) $comment['comment_id'] : 0;
                $cAuthorId    = is_numeric($comment['author_id']) ? (int) $comment['author_id'] : 0;

                /** @var array<string, float|int|string|null> $element_row */
                $element_row  = $elements[(string) $cImageId] ?? [];
                $name         = (isset($element_row['name']) && $element_row['name'] !== '') ? (string) $element_row['name'] : StringUtil::getNameFromFile((string) ($element_row['file'] ?? ''));
                $src_image    = new SrcImage($element_row);
                $url          = $this->urlService->makePictureUrl([
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
                    'WEBSITE_URL' => $comment['website_url'],
                    'DATE'        => $this->dateService->formatDate($cDate, ['day_name', 'day', 'month', 'year', 'time']),
                ];
                $authorEvent = new RenderCommentAuthor((string) ($comment['author'] ?? ''));
                $this->dispatcher->dispatch($authorEvent);
                $tpl_comment['AUTHOR'] = $authorEvent->commentAuthor;
                $contentEvent = new RenderCommentContent((string) ($comment['content'] ?? ''));
                $this->dispatcher->dispatch($contentEvent);
                $tpl_comment['CONTENT'] = new Html($contentEvent->commentContent);

                if ($this->permissionService->isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }
                if ($this->permissionService->canManageComment('delete', $cAuthorId)) {
                    $tpl_comment['U_DELETE'] = $this->urlService->addUrlParams($url_self, ['delete' => $cId, 'pwg_token' => $this->csrfService->getToken()]);
                }
                if ($this->permissionService->canManageComment('edit', $cAuthorId)) {
                    $tpl_comment['U_EDIT'] = $this->urlService->addUrlParams($url_self, ['edit' => $cId]);
                    if ($edit_comment !== null && $cId == $edit_comment) {
                        $key = $this->ephemeralKeyService->generate(2, (string) $cImageId);
                        $tpl_comment['IN_EDIT']   = true;
                        $tpl_comment['KEY']       = $key;
                        $tpl_comment['IMAGE_ID']  = $cImageId;
                        $tpl_comment['CONTENT']   = (string) ($comment['content'] ?? '');
                        $tpl_comment['PWG_TOKEN'] = $this->csrfService->getToken();
                        $tpl_comment['U_CANCEL']  = $url_self;
                    }
                }
                if ($this->permissionService->canManageComment('validate', $cAuthorId) && !BoolUtil::fromMixed($comment['validated'])) {
                    $tpl_comment['U_VALIDATE'] = $this->urlService->addUrlParams($url_self, ['validate' => $cId, 'pwg_token' => $this->csrfService->getToken()]);
                }
                $tpl->append('comments', $tpl_comment);
            }
        }

        $commentsDerivEvent = new GetCommentsDerivativeParams(ImageStdParams::getByType(DerivativeSize::Thumb->value));
        $this->dispatcher->dispatch($commentsDerivEvent);
        $tpl->assign('comment_derivative_params', $commentsDerivEvent->value);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theCommentsPage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        PageHeaderRenderer::render($title);
        $this->dispatcher->dispatch(new LocEndComments());
        $this->htmlService->flushPageMessages();
        if (count($comments) > 0) {
            $tpl->assignVarFromTemplate('COMMENT_LIST', 'comment_list.latte');
        }
        $tpl->pparse('comments.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
