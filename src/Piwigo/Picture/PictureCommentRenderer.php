<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Projection\CommentInsertData;
use Piwigo\Common\Enum\SortOrder;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Html\Event\RenderCommentContent;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\Event\RenderCommentAuthor;
use Piwigo\Picture\Event\UserCommentInsertion;
use Piwigo\Picture\Projection\CommentAddForm;
use Piwigo\Picture\Projection\CommentListView;
use Piwigo\Picture\Projection\CommentRow;
use Piwigo\Picture\Projection\PictureCommentsResult;
use Piwigo\Picture\Request\PictureCommentSubmitRequest;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;

/**
 * Renders the picture page's comment list + add/edit form. Ported from
 * include/picture_comment.inc.php.
 *
 * $editCommentId is passed explicitly by the caller (PictureController),
 * whose own can_manage_comment('edit', $author_id) check decides whether
 * a real id ever reaches this method; this class's own
 * can_manage_comment('edit', $comment_author_id) check (below) separately
 * decides whether any given comment row honors it.
 *
 * render()'s two reject paths ("Session expired" / "ugly spammer") throw
 * {@see \Piwigo\Http\ResponseReadyException}, caught by
 * Http\Middleware\ControllerInvokerMiddleware like every other
 * controller. "Session expired" keeps a 200 status (no explicit
 * setStatusHeader() call precedes it); "ugly spammer" uses an explicit
 * 403.
 */
final class PictureCommentRenderer
{
    /**
     * $start deliberately reuses the gallery grid's own start-offset value
     * (see the nav-bar URL below stripping+reusing it) -- this is the
     * comment list's own pagination offset, not a separate value.
     */
    /**
     * @param list<array<string, mixed>> $related_categories
     *   {@see \Piwigo\Image\Projection\VisibleCategoryRow::toArray()}'s own
     *   shape in production ({@see \Piwigo\Controller\PictureController}
     *   flattens it right at this call) -- `commentable` is a real `bool`
     *   there, but this method's own type stays loose: its own
     *   mutation-kill tests below deliberately construct a row missing
     *   the key entirely, to observe the resulting "Undefined array key"
     *   warning.
     */
    public function render(Lang $lang, AccessLevelChecker $accessLevelChecker, ?CommentId $editCommentId, int $imageId, int $start, UrlServiceInterface $urlService, array $related_categories, string $url_self, SessionService $sessionService, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentConfig $currentConfig, CsrfService $csrfService, MailerInterface $mailer, HtmlRenderingInterface $htmlRenderer, EntityManagerInterface $entityManager, Renderer $renderer): PictureCommentsResult
    {
        $commentRepository = TypedRepository::narrow($entityManager->getRepository(CommentEntity::class), CommentRepository::class);
        $commentService = new CommentService($lang, $commentRepository, new EphemeralKeyService($currentConfig), $mailer, $htmlRenderer, $urlService, $eventDispatcher, $pageState, $currentUser, $currentConfig, $accessLevelChecker);

        $commentAction = null;

        $pictureCommentSubmitRequest = PictureCommentSubmitRequest::fromGlobals();
        $showComments = array_any($related_categories, fn (array $category): bool => (bool) $category['commentable']);

        if ($showComments and $pictureCommentSubmitRequest->contentPresent) {
            if ($accessLevelChecker->isAGuest() and ! $currentConfig->commentsForall) {
                throw new ResponseReadyException(ResponseFactory::text('Session expired'));
            }

            $postAuthor = $pictureCommentSubmitRequest->author;
            $postContent = $pictureCommentSubmitRequest->content;
            $postWebsiteUrl = $pictureCommentSubmitRequest->websiteUrl;
            $postEmail = $pictureCommentSubmitRequest->email;

            $comm = new CommentInsertData(
                author: $postAuthor !== null && $postAuthor !== '' && $postAuthor !== '0' ? trim($postAuthor) : '',
                content: $postContent !== null && $postContent !== '' && $postContent !== '0' ? trim($postContent) : '',
                imageId: $imageId,
                websiteUrl: $postWebsiteUrl !== null && $postWebsiteUrl !== '' && $postWebsiteUrl !== '0' ? trim($postWebsiteUrl) : '',
                email: $postEmail !== null && $postEmail !== '' && $postEmail !== '0' ? trim($postEmail) : '',
            );

            $postKey = $pictureCommentSubmitRequest->key;
            // insertComment() overwrites $commentErrors unconditionally as its
            // very first statement, so any prior contents are never actually
            // read by it; a fresh array is passed and the result is written
            // back below.
            $commentErrors = [];
            $commentAction = $commentService->insertComment($comm, $postKey ?? '', $commentErrors);
            $pageState->errors = $commentErrors;

            // Narrowed once into local variables and written back after the
            // switch, so the case bodies below don't re-read PageState
            // directly -- switch branches lose property narrowing in this
            // codebase.
            $commentInfos = $pageState->infos;

            switch ($commentAction) {
                case 'moderate':
                    $commentInfos[] = $lang->t('An administrator must authorize your comment before it is visible.');
                    // no break
                case 'validate':
                    $commentInfos[] = $lang->t('Your comment has been registered');
                    break;
                case 'reject':
                    $htmlRenderer
                        ->setStatusHeader(403);
                    $commentErrors[] = $lang->t('Your comment has NOT been registered because it did not pass the validation rules');
                    break;
                default:
                    trigger_error('Invalid comment action ' . $commentAction, E_USER_WARNING);
            }

            $pageState->infos = $commentInfos;
            $pageState->errors = $commentErrors;

            // allow plugins to notify what's going on
            $eventDispatcher->dispatch(new UserCommentInsertion(
                array_merge($comm->toArray(), [
                    'action' => $commentAction,
                ])
            ));
        } elseif ($pictureCommentSubmitRequest->contentPresent) {
            throw new ResponseReadyException(ResponseFactory::text('ugly spammer', 403));
        }

        if (! $showComments) {
            return PictureCommentsResult::empty();
        }

        $onlyValidated = ! $accessLevelChecker->isAdmin();

        // number of comments for this picture
        $nbComments = $commentRepository->countForImage(ImageId::from($imageId), $onlyValidated);

        // navigation bar creation
        $nbCommentPage = $currentConfig->nbCommentPage;

        $navigationBar = new PaginationService($currentConfig)
            ->createNavigationBar($urlService->duplicatePictureUrl([], ['start']), $nbComments, $start, $nbCommentPage, true);

        $comments = [];
        $commentsOrderUrl = null;
        $commentsOrderTitle = null;

        if ($nbComments > 0) {
            // comments order (get, session, conf)
            $getCommentsOrder = $pictureCommentSubmitRequest->commentsOrderRaw;
            if ($getCommentsOrder !== null && $getCommentsOrder !== '' && $getCommentsOrder !== '0' && in_array(strtoupper($getCommentsOrder), [SortOrder::Asc->value, SortOrder::Desc->value], true)) {
                $sessionService->setSessionVar('comments_order', $getCommentsOrder);
            }
            $commentsOrder = $sessionService->getCommentsOrder() ?? $currentConfig->commentsOrder;

            $commentsOrderUrl = $urlService->addUrlParams($urlService->duplicatePictureUrl(), [
                'comments_order' => ($commentsOrder === SortOrder::Asc->value ? SortOrder::Desc->value : SortOrder::Asc->value),
            ]);
            $commentsOrderTitle = $commentsOrder === SortOrder::Asc->value ? $lang->t('Show latest comments first') : $lang->t('Show oldest comments first');

            $rows = $commentRepository->findForImage(
                ImageId::from($imageId),
                $onlyValidated,
                $commentsOrder,
                $nbCommentPage,
                $start
            );

            foreach ($rows as $row) {
                $author = $row->author === 'guest' ? $lang->t('guest') : $row->author;

                $email = null;
                if ($row->userEmail !== null && $row->userEmail !== '' && $row->userEmail !== '0') {
                    $email = $row->userEmail;
                } elseif ($row->email !== null && $row->email !== '' && $row->email !== '0') {
                    $email = $row->email;
                }

                // com.date is nullable; every real insert still sets it
                // explicitly (CommentRepository::insert()), but
                // formatDate()'s own `false` "no date" sentinel is the
                // correct fallback here, not an assert() -- matches
                // Comments::getList()'s own identical guard for this
                // same column.
                $rowDate = $row->date ?? false;

                $authorEvent = $eventDispatcher->dispatch(new RenderCommentAuthor($author ?? ''));

                $contentEvent = $eventDispatcher->dispatch(new RenderCommentContent($row->content ?? ''));

                // Every conditional field below is collected first and
                // handed to one constructor call, rather than written
                // onto a growing array: the template asks about each of
                // them, and a readonly row is what makes those questions
                // answerable without isset().
                $deleteUrl = null;
                $editUrl = null;
                $cancelUrl = null;
                $validateUrl = null;
                $inEdit = false;
                $key = null;
                $csrfToken = null;
                $emailForRow = null;
                $content = $contentEvent->commentContent;

                // com.author_id allows NULL (anonymous/guest comments); no
                // real user id is ever negative, so -1 is a safe
                // "never matches" sentinel.
                $commentAuthorId = $row->authorId ?? -1;

                if ($accessLevelChecker->canManageComment('delete', $commentAuthorId)) {
                    $deleteUrl = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $row->id->value,
                            'pwg_token' => $csrfService
                                ->getToken(),
                        ]
                    );
                }
                if ($accessLevelChecker->canManageComment('edit', $commentAuthorId)) {
                    $editUrl = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'edit_comment',
                            'comment_to_edit' => $row->id->value,
                        ]
                    );
                    if ($editCommentId instanceof CommentId and $row->id->equals($editCommentId)) {
                        $inEdit = true;
                        $key = new EphemeralKeyService($currentConfig)
                            ->generate(2, (string) $imageId);
                        $content = $row->content;
                        $csrfToken = $csrfService->getToken();
                        $cancelUrl = $url_self;
                    }
                }
                if ($accessLevelChecker->isAdmin()) {
                    $emailForRow = $email;

                    if (! $row->validated) {
                        $validateUrl = $urlService->addUrlParams(
                            $url_self,
                            [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $row->id->value,
                                'pwg_token' => $csrfService
                                    ->getToken(),
                            ]
                        );
                    }
                }

                $comments[] = new CommentRow(
                    id: $row->id->value,
                    author: $authorEvent->commentAuthor,
                    date: DateHelper::formatDate($rowDate, ['day_name', 'day', 'month', 'year', 'time']),
                    content: $content,
                    // '' is what an empty website field is stored as;
                    // CommentRow treats absence as null so the template can
                    // ask one question instead of two.
                    websiteUrl: $row->websiteUrl === '' ? null : $row->websiteUrl,
                    email: $emailForRow,
                    deleteUrl: $deleteUrl,
                    editUrl: $editUrl,
                    cancelUrl: $cancelUrl,
                    validateUrl: $validateUrl,
                    inEdit: $inEdit,
                    key: $key,
                    csrfToken: $csrfToken,
                );
            }
        }

        $showAddCommentForm = true;
        if ($editCommentId instanceof CommentId) {
            $showAddCommentForm = false;
        }
        if ($accessLevelChecker->isAGuest() and ! $currentConfig->commentsForall) {
            $showAddCommentForm = false;
        }

        $commentAdd = null;
        if ($showAddCommentForm) {
            $key = new EphemeralKeyService($currentConfig)
                ->generate(3, (string) $imageId);

            $userEmail = $currentUser->get()
                ->email;
            $userEmailEmpty = ! $userEmail instanceof Email;

            // A rejected submission comes back into the form; a first
            // render starts empty. `escapeSubmitted()` is where the old
            // `$tplVar[strtoupper($k)] = ...` dynamic-key write went --
            // four named fields, not a loop over a map whose keys had to
            // match the array's by convention.
            $rejected = $commentAction === 'reject';

            $commentAdd = new CommentAddForm(
                formAction: $url_self,
                key: $key,
                content: $rejected ? self::escapeSubmitted($pictureCommentSubmitRequest->content) : '',
                showAuthor: ! $accessLevelChecker->isClassicUser(),
                authorMandatory: $currentConfig->commentsAuthorMandatory,
                author: $rejected ? self::escapeSubmitted($pictureCommentSubmitRequest->author) : '',
                websiteUrl: $rejected ? self::escapeSubmitted($pictureCommentSubmitRequest->websiteUrl) : '',
                showEmail: ! $accessLevelChecker->isClassicUser() || $userEmailEmpty,
                emailMandatory: $currentConfig->commentsEmailMandatory,
                email: $rejected ? self::escapeSubmitted($pictureCommentSubmitRequest->email) : '',
                showWebsite: $currentConfig->commentsEnableWebsite,
            );
        }

        $commentList = $renderer->render(new CommentListView(comments: $comments, commentDerivativeParams: null, rootUrl: $urlService->getRootUrl(), iconDir: ''));

        return new PictureCommentsResult(
            commentsOrderUrl: $commentsOrderUrl,
            commentsOrderTitle: $commentsOrderTitle,
            commentCount: $nbComments,
            commentsNavbar: $navigationBar,
            comments: $comments,
            commentAdd: $commentAdd,
            commentList: $commentList,
        );
    }

    /**
     * The submitted value, HTML-escaped for the `value=` attribute it is
     * about to be echoed back into, or '' when the field was not sent.
     */
    private static function escapeSubmitted(?string $value): string
    {
        return $value !== null ? htmlspecialchars($value) : '';
    }
}
