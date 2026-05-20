<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Latte\Runtime\Html;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentModerationAction;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Event\User\UserCommentInsertion;
use Piwigo\Exception\AuthException;
use Piwigo\Html\HtmlService;
use Piwigo\Page\PaginationService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\Session;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class PictureCommentRenderer
{
    public function __construct(
        private CommentRepository $commentRepository,
        private CommentService $commentService,
        private DateService $dateService,
        private HtmlService $htmlService,
        private PermissionService $permissionService,
        private Session $session,
        private UrlService $urlService,
        private CsrfService $csrfService,
        private EphemeralKeyService $ephemeralKeyService,
        private PaginationService $paginationService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function render(?int $editComment = null): void
    {
        $template           = TemplateRegistry::current();
        $picCtx             = PictureContextRegistry::current();
        $imageId            = $picCtx->currentItem;
        $related_categories = $picCtx->relatedCategories;
        $url_self           = $this->urlService->duplicatePictureUrl();
        $showComments = array_any($related_categories, fn ($category): bool => BoolUtil::fromMixed($category['commentable'] ?? null));

        $comment_action = null;

        if ($showComments and isset($_POST['content'])) {
            if ($this->permissionService->isAGuest() and !Config::commentsForall()) {
                throw new AuthException('Session expired');
            }

            $postAuthor = $_POST['author'] ?? null;
            $postWebsite = $_POST['website_url'] ?? null;
            $postEmail = $_POST['email'] ?? null;
            $comm = [
                'author' => ($postAuthor === null || $postAuthor === '' || !is_string($postAuthor)) ? '' : trim($postAuthor),
                'content' => ($_POST['content'] === '' || !is_string($_POST['content'])) ? '' : trim($_POST['content']),
                'website_url' => ($postWebsite === null || $postWebsite === '' || !is_string($postWebsite)) ? '' : trim($postWebsite),
                'email' => ($postEmail === null || $postEmail === '' || !is_string($postEmail)) ? '' : trim($postEmail),
                'image_id' => $imageId,
            ];

            $post_key = $_POST['key'] ?? '';
            /** @var list<string> $commentErrors */
            $commentErrors = [];
            $comment_action = $this->commentService->insertUserComment($comm, is_string($post_key) ? $post_key : '', $commentErrors);
            foreach ($commentErrors as $err) {
                PageState::current()->addError($err);
            }

            switch ($comment_action) {
                case CommentModerationAction::Moderate:
                    PageState::current()->addInfo(Lang::t('An administrator must authorize your comment before it is visible.'));
                    // no break
                case CommentModerationAction::Validate:
                    PageState::current()->addInfo(Lang::t('Your comment has been registered'));
                    break;
                case CommentModerationAction::Reject:
                    $this->htmlService->setStatusHeader(403);
                    PageState::current()->addError(Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
                    break;
            }

            $this->dispatcher->dispatch(new UserCommentInsertion(array_merge($comm, ['action' => $comment_action->value])));
        } elseif (isset($_POST['content'])) {
            $this->htmlService->setStatusHeader(403);
            throw new AuthException('ugly spammer');
        }

        if ($showComments) {
            $validatedOnly = !$this->permissionService->isAdmin();
            $nb_comments   = $this->commentRepository->countByImageId($imageId, $validatedOnly);
            $startOffset   = SectionContextRegistry::current()->start;

            $navigation_bar = $this->paginationService->createNavigationBar(
                $this->urlService->duplicatePictureUrl([], ['start']),
                $nb_comments,
                $startOffset,
                Config::nbCommentPage(),
                true
            );

            $template->assign([
                'COMMENT_COUNT' => $nb_comments,
                'navbar' => $navigation_bar,
                'comments' => [],
            ]);

            if ($nb_comments > 0) {
                $get_comments_order = $_GET['comments_order'] ?? null;
                if (($get_comments_order !== null && $get_comments_order !== '') && in_array(strtoupper(is_string($get_comments_order) ? $get_comments_order : ''), ['ASC', 'DESC'])) {
                    $this->session->commentsOrder = is_string($get_comments_order) ? $get_comments_order : '';
                }
                $comments_order = $this->session->commentsOrder ?? Config::commentsOrder();

                $template->assign([
                    'COMMENTS_ORDER_URL' => $this->urlService->addUrlParams($this->urlService->duplicatePictureUrl(), ['comments_order' => ($comments_order == 'ASC' ? 'DESC' : 'ASC')]),
                    'COMMENTS_ORDER_TITLE' => $comments_order == 'ASC' ? Lang::t('Show latest comments first') : Lang::t('Show oldest comments first'),
                ]);

                $commentRows = $this->commentRepository->findForImagePage(
                    $imageId,
                    $validatedOnly,
                    $comments_order,
                    Config::nbCommentPage(),
                    $startOffset,
                    Tables::users(),
                    Config::userFields()->id,
                    Config::userFields()->email,
                );

                foreach ($commentRows as $row) {
                    $author = $row->author === 'guest' ? Lang::t('guest') : ($row->author ?? '');

                    $email = $row->userEmail ?? $row->email;
                    if ($email === '' || $email === null) {
                        $email = null;
                    }

                    $contentEvent = new RenderCommentContent($row->content ?? '');
                    $this->dispatcher->dispatch($contentEvent);
                    $authorEvent = new RenderCommentAuthor($author);
                    $this->dispatcher->dispatch($authorEvent);
                    $rowId       = $row->id->value;
                    $authorId    = $row->authorId !== null ? $row->authorId->value : 0;
                    $tpl_comment = [
                        'ID' => $rowId,
                        'AUTHOR' => $authorEvent->commentAuthor,
                        'DATE' => $this->dateService->formatDate($row->date !== null ? $row->date->value : '', ['day_name', 'day', 'month', 'year', 'time']),
                        'CONTENT' => new Html($contentEvent->commentContent),
                        'WEBSITE_URL' => $row->websiteUrl,
                    ];

                    if ($this->permissionService->canManageComment('delete', $authorId)) {
                        $tpl_comment['U_DELETE'] = $this->urlService->addUrlParams($url_self, [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $rowId,
                            'pwg_token' => $this->csrfService->getToken(),
                        ]);
                    }
                    if ($this->permissionService->canManageComment('edit', $authorId)) {
                        $tpl_comment['U_EDIT'] = $this->urlService->addUrlParams($url_self, [
                            'action' => 'edit_comment',
                            'comment_to_edit' => $rowId,
                        ]);
                        if ($editComment !== null && $rowId === $editComment) {
                            $tpl_comment['IN_EDIT'] = true;
                            $key = $this->ephemeralKeyService->generate(2, (string) $imageId);
                            $tpl_comment['KEY'] = $key;
                            $tpl_comment['CONTENT'] = $row->content;
                            $tpl_comment['PWG_TOKEN'] = $this->csrfService->getToken();
                            $tpl_comment['U_CANCEL'] = $url_self;
                        }
                    }
                    if ($this->permissionService->isAdmin()) {
                        $tpl_comment['EMAIL'] = $email;

                        if (!$row->validated) {
                            $tpl_comment['U_VALIDATE'] = $this->urlService->addUrlParams($url_self, [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $rowId,
                                'pwg_token' => $this->csrfService->getToken(),
                            ]);
                        }
                    }
                    $template->append('comments', $tpl_comment);
                }
            }

            $show_add_comment_form = true;
            if ($editComment !== null) {
                $show_add_comment_form = false;
            }
            if ($this->permissionService->isAGuest() and !Config::commentsForall()) {
                $show_add_comment_form = false;
            }

            if ($show_add_comment_form) {
                $key = $this->ephemeralKeyService->generate(3, (string) $imageId);

                $tpl_var = [
                    'F_ACTION' => $url_self,
                    'KEY' => $key,
                    'CONTENT' => '',
                    'SHOW_AUTHOR' => !$this->permissionService->isClassicUser(),
                    'AUTHOR_MANDATORY' => Config::commentsAuthorMandatory(),
                    'AUTHOR' => '',
                    'WEBSITE_URL' => '',
                    'SHOW_EMAIL' => !$this->permissionService->isClassicUser() or empty(CurrentUser::get()->email),
                    'EMAIL_MANDATORY' => Config::commentsEmailMandatory(),
                    'EMAIL' => '',
                    'SHOW_WEBSITE' => Config::commentsEnableWebsite(),
                ];

                if ('reject' == $comment_action) {
                    foreach (['content', 'author', 'website_url', 'email'] as $k) {
                        $post_val = $_POST[$k] ?? null;
                        $tpl_var[strtoupper($k)] = (isset($post_val) && is_string($post_val)) ? htmlspecialchars(stripslashes($post_val)) : '';
                    }
                }
                $template->assign('comment_add', $tpl_var);
            }
            $template->assignVarFromTemplate('COMMENT_LIST', 'comment_list.latte');
        }
    }
}
