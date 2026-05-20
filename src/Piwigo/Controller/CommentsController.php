<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentManagementAction;
use Piwigo\Comment\CommentModerationAction;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
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
use Piwigo\Session\Session;
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
        private Session $session,
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
            $whereClauses[] = '(u.' . Config::userFields()->username . ' = \'' . $get_author . '\' OR author = \'' . $get_author . '\')';
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

        $perm1 = $this->permissionService->getSqlConditionFandF(
            ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'ic.image_id'],
            '',
            true
        );
        $whereClauses[] = $perm1->where;

        // Comment actions
        $comment_id = 0;
        $action     = null;
        $edit_comment = null;

        foreach (CommentManagementAction::cases() as $loop_action) {
            if (isset($_GET[$loop_action->value])) {
                $action = $loop_action;
                $this->inputValidator->check($action->value, $_GET, false, ValidationPattern::ID);
                $actionRaw = $_GET[$action->value] ?? null;
                $comment_id = is_numeric($actionRaw) ? (int) $actionRaw : 0;
                break;
            }
        }

        if ($action !== null && $this->permissionService->canManageComment($action, $this->commentService->getCommentAuthorId($comment_id))) {
            $perform_redirect = false;
            if (CommentManagementAction::Delete === $action) {
                $this->csrfService->check();
                $this->commentService->deleteUserComment($comment_id);
                $perform_redirect = true;
            }
            if (CommentManagementAction::Validate === $action) {
                $this->csrfService->check();
                $this->commentService->validateUserComment($comment_id);
                $perform_redirect = true;
            }
            if (CommentManagementAction::Edit === $action) {
                $post_content = StringUtil::inputString('content', null, $_POST);
                if ($post_content !== null && $post_content !== '') {
                    $this->csrfService->check();
                    $comment_action = $this->commentService->updateUserComment(
                        ['comment_id' => $comment_id, 'image_id' => StringUtil::inputInt('image_id', null, $_POST), 'content' => $post_content, 'website_url' => StringUtil::inputString('website_url', null, $_POST)],
                        StringUtil::inputString('key', null, $_POST) ?? ''
                    );
                    switch ($comment_action) {
                        case CommentModerationAction::Moderate:
                            $this->session->flash->add('info', Lang::t('An administrator must authorize your comment before it is visible.'));
                            // no break
                        case CommentModerationAction::Validate:
                            $this->session->flash->add('info', Lang::t('Your comment has been registered'));
                            $perform_redirect = true;
                            break;
                        case CommentModerationAction::Reject:
                            $this->session->flash->add('error', Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
                            break;
                    }
                }
                $edit_comment = $comment_id;
            }
            if ($perform_redirect) {
                $this->redirectResponder->redirect($url_self);
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
        $catPerm = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'WHERE');
        $query = '
SELECT id, name, uppercats, global_rank
  FROM ' . Tables::categories() . '
' . $catPerm->where . '
;';
        $this->categoryService->displaySelectCatWrapper($query, array_filter([$get_cat], fn (mixed $v): bool => $v !== null), $blockname, true, $catPerm->params, $catPerm->types);

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

        $userEmailField = Config::userFields()->email;
        $userIdField    = Config::userFields()->id;
        $usersTable     = Tables::users();

        $counter = $this->commentRepository->countFilteredComments($whereClauses, $perm1->params, $perm1->types, $usersTable, $userIdField);

        $pageItemsNumber = $itemsNumber;
        $rowLimit  = 'all' == $pageItemsNumber ? -1 : (int) $pageItemsNumber;
        $rowOffset = $start ?? 0;
        foreach ($this->commentRepository->findFilteredComments(
            $whereClauses,
            $perm1->params,
            $perm1->types,
            $usersTable,
            $userIdField,
            $userEmailField,
            $sortBy,
            $sortOrder,
            $rowLimit,
            $rowOffset,
        ) as $row) {
            $comments[]     = $row;
            $element_ids[]  = $row->imageId->value;
            $category_ids[] = $row->categoryId->value;
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
            $elements = [];
            foreach ($this->imageRepository->findByIds($element_ids) as $img) {
                $elements[$img->id->value] = $img;
            }
            $categories = $this->categoryRepository->findNamePermalinkUppercatsByIds($category_ids);

            foreach ($comments as $comment) {
                $cImageId    = $comment->imageId->value;
                $cCategoryId = $comment->categoryId->value;
                $cId         = $comment->commentId->value;
                $cAuthorId   = $comment->authorId !== null ? $comment->authorId->value : 0;

                $element      = $elements[$cImageId] ?? null;
                $elementFile  = $element !== null ? $element->file->value : '';
                $name         = ($element !== null && $element->name !== null && $element->name !== '')
                    ? $element->name
                    : StringUtil::getNameFromFile($elementFile);
                $src_image    = $element !== null ? SrcImage::fromImage($element) : new SrcImage([]);
                $url          = $this->urlService->makePictureUrl([
                    'category'   => isset($categories[$cCategoryId]) ? $categories[$cCategoryId]->toRow() : [],
                    'image_id'   => $cImageId,
                    'image_file' => $elementFile,
                ]);

                $email = ($comment->userEmail !== null && $comment->userEmail !== '') ? $comment->userEmail
                       : (($comment->email !== null && $comment->email !== '') ? $comment->email : null);

                $cDate = $comment->date?->value;
                $tpl_comment = [
                    'ID'          => $cId,
                    'U_PICTURE'   => $url,
                    'src_image'   => $src_image,
                    'ALT'         => $name,
                    'WEBSITE_URL' => $comment->websiteUrl,
                    'DATE'        => $this->dateService->formatDate($cDate, ['day_name', 'day', 'month', 'year', 'time']),
                ];
                $authorEvent = new RenderCommentAuthor($comment->author ?? '');
                $this->dispatcher->dispatch($authorEvent);
                $tpl_comment['AUTHOR'] = $authorEvent->commentAuthor;
                $contentEvent = new RenderCommentContent($comment->content ?? '');
                $this->dispatcher->dispatch($contentEvent);
                $tpl_comment['CONTENT'] = new Html($contentEvent->commentContent);

                if ($this->permissionService->isAdmin()) {
                    $tpl_comment['EMAIL'] = $email;
                }
                if ($this->permissionService->canManageComment(CommentManagementAction::Delete, $cAuthorId)) {
                    $tpl_comment['U_DELETE'] = $this->urlService->addUrlParams($url_self, ['delete' => $cId, 'pwg_token' => $this->csrfService->getToken()]);
                }
                if ($this->permissionService->canManageComment(CommentManagementAction::Edit, $cAuthorId)) {
                    $tpl_comment['U_EDIT'] = $this->urlService->addUrlParams($url_self, ['edit' => $cId]);
                    if ($edit_comment !== null && $cId === $edit_comment) {
                        $key = $this->ephemeralKeyService->generate(2, (string) $cImageId);
                        $tpl_comment['IN_EDIT']   = true;
                        $tpl_comment['KEY']       = $key;
                        $tpl_comment['IMAGE_ID']  = $cImageId;
                        $tpl_comment['CONTENT']   = $comment->content ?? '';
                        $tpl_comment['PWG_TOKEN'] = $this->csrfService->getToken();
                        $tpl_comment['U_CANCEL']  = $url_self;
                    }
                }
                if ($this->permissionService->canManageComment(CommentManagementAction::Validate, $cAuthorId) && !$comment->validated) {
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
