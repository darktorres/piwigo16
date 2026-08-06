<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Common\Enum\SortOrder;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Event\User\UserCommentInsertion;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\Request\PictureCommentSubmitRequest;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
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
     * @param list<array<string, mixed>> $related_categories row shape is
     *   mixed, not uniformly string|null: `commentable` is this table's
     *   real tinyint column (int|bool depending on the driver), `id` a
     *   native DBAL int -- only `uppercats`/`status`/`global_rank` are
     *   genuinely string|null.
     */
    public function render(Lang $lang, AccessLevelChecker $accessLevelChecker, ?CommentId $editCommentId, int $imageId, int $start, UrlServiceInterface $urlService, array $related_categories, string $url_self, SessionService $sessionService, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, MailerInterface $mailer, HtmlRenderingInterface $htmlRenderer): void
    {
        $template = $currentTemplate->get();

        $commentRepository = EntityManagerFactory::build(DbConnection::build())->getRepository(CommentEntity::class);
        $commentService = new CommentService($lang, $commentRepository, new EphemeralKeyService($currentConfig), $mailer, $htmlRenderer, $urlService, $eventDispatcher, $pageState, $currentUser, $currentConfig, $accessLevelChecker);

        $commentAction = null;

        $pictureCommentSubmitRequest = PictureCommentSubmitRequest::fromGlobals();

        // the picture is commentable if it belongs at least to one category
        // which is commentable
        $showComments = false;
        foreach ($related_categories as $category) {
            if ((bool) $category['commentable']) {
                $showComments = true;
                break;
            }
        }

        if ($showComments and $pictureCommentSubmitRequest->contentPresent) {
            if ($accessLevelChecker->isAGuest() and ! $currentConfig->commentsForall()) {
                throw new ResponseReadyException(ResponseFactory::text('Session expired'));
            }

            $postAuthor = $pictureCommentSubmitRequest->author;
            $postContent = $pictureCommentSubmitRequest->content;
            $postWebsiteUrl = $pictureCommentSubmitRequest->websiteUrl;
            $postEmail = $pictureCommentSubmitRequest->email;

            $comm = [
                'author' => $postAuthor !== null && $postAuthor !== '' && $postAuthor !== '0' ? trim($postAuthor) : '',
                'content' => $postContent !== null && $postContent !== '' && $postContent !== '0' ? trim($postContent) : '',
                'website_url' => $postWebsiteUrl !== null && $postWebsiteUrl !== '' && $postWebsiteUrl !== '0' ? trim($postWebsiteUrl) : '',
                'email' => $postEmail !== null && $postEmail !== '' && $postEmail !== '0' ? trim($postEmail) : '',
                'image_id' => $imageId,
            ];

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
            $eventDispatcher->dispatchNotify(new UserCommentInsertion(
                array_merge($comm, [
                    'action' => $commentAction,
                ])
            ));
        } elseif ($pictureCommentSubmitRequest->contentPresent) {
            throw new ResponseReadyException(ResponseFactory::text('ugly spammer', 403));
        }

        if (! $showComments) {
            return;
        }

        $onlyValidated = ! $accessLevelChecker->isAdmin();

        // number of comments for this picture
        $nbComments = $commentRepository->countForImage($imageId, $onlyValidated);

        // navigation bar creation
        $nbCommentPage = $currentConfig->nbCommentPage();

        $navigationBar = new PaginationService($currentConfig)
            ->createNavigationBar($urlService->duplicatePictureUrl([], ['start']), $nbComments, $start, $nbCommentPage, true);

        $template->assign(
            [
                'COMMENT_COUNT' => $nbComments,
                'navbar' => $navigationBar,
                'comments' => [],
            ]
        );

        if ($nbComments > 0) {
            // comments order (get, session, conf)
            $getCommentsOrder = $pictureCommentSubmitRequest->commentsOrderRaw;
            if ($getCommentsOrder !== null && $getCommentsOrder !== '' && $getCommentsOrder !== '0' && in_array(strtoupper($getCommentsOrder), [SortOrder::Asc->value, SortOrder::Desc->value], true)) {
                $sessionService->setSessionVar('comments_order', $getCommentsOrder);
            }
            $commentsOrder = $sessionService->getSessionVar('comments_order', $currentConfig->commentsOrder());
            $commentsOrder = is_string($commentsOrder) ? $commentsOrder : SortOrder::Asc->value;

            $template->assign([
                'COMMENTS_ORDER_URL' => $urlService->addUrlParams($urlService->duplicatePictureUrl(), [
                    'comments_order' => ($commentsOrder === SortOrder::Asc->value ? SortOrder::Desc->value : SortOrder::Asc->value),
                ]),
                'COMMENTS_ORDER_TITLE' => $commentsOrder === SortOrder::Asc->value ? $lang->t('Show latest comments first') : $lang->t('Show oldest comments first'),
            ]);

            $rows = $commentRepository->findForImage(
                $imageId,
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
                // PwgComments::getList()'s own identical guard for this
                // same column.
                $rowDate = $row->date ?? false;

                $authorEvent = $eventDispatcher->dispatchChange(new RenderCommentAuthor($author ?? ''));

                $contentEvent = $eventDispatcher->dispatchChange(new RenderCommentContent($row->content ?? ''));

                $tplComment =
                  [
                      'ID' => $row->id->value,
                      'AUTHOR' => $authorEvent->commentAuthor,
                      'DATE' => DateHelper::formatDate($rowDate, ['day_name', 'day', 'month', 'year', 'time']),
                      'CONTENT' => $contentEvent->commentContent,
                      'WEBSITE_URL' => $row->websiteUrl,
                  ];

                // com.author_id allows NULL (anonymous/guest comments); no
                // real user id is ever negative, so -1 is a safe
                // "never matches" sentinel.
                $commentAuthorId = $row->authorId ?? -1;

                if ($accessLevelChecker->canManageComment('delete', $commentAuthorId)) {
                    $tplComment['U_DELETE'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $row->id->value,
                            'pwg_token' => new CsrfService($currentConfig)
                                ->getToken(),
                        ]
                    );
                }
                if ($accessLevelChecker->canManageComment('edit', $commentAuthorId)) {
                    $tplComment['U_EDIT'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'edit_comment',
                            'comment_to_edit' => $row->id->value,
                        ]
                    );
                    if ($editCommentId !== null and $row->id->equals($editCommentId)) {
                        $tplComment['IN_EDIT'] = true;
                        $key = new EphemeralKeyService($currentConfig)
                            ->generate(2, (string) $imageId);
                        $tplComment['KEY'] = $key;
                        $tplComment['CONTENT'] = $row->content;
                        $tplComment['PWG_TOKEN'] = new CsrfService($currentConfig)->getToken();
                        $tplComment['U_CANCEL'] = $url_self;
                    }
                }
                if ($accessLevelChecker->isAdmin()) {
                    $tplComment['EMAIL'] = $email;

                    if (! $row->validated) {
                        $tplComment['U_VALIDATE'] = $urlService->addUrlParams(
                            $url_self,
                            [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $row->id->value,
                                'pwg_token' => new CsrfService($currentConfig)
                                    ->getToken(),
                            ]
                        );
                    }
                }
                $template->append('comments', $tplComment);
            }
        }

        $showAddCommentForm = true;
        if ($editCommentId !== null) {
            $showAddCommentForm = false;
        }
        if ($accessLevelChecker->isAGuest() and ! $currentConfig->commentsForall()) {
            $showAddCommentForm = false;
        }

        if ($showAddCommentForm) {
            $key = new EphemeralKeyService($currentConfig)
                ->generate(3, (string) $imageId);

            $userEmail = $currentUser->get()
                ->email;
            $userEmailEmpty = $userEmail === '' || $userEmail === '0';

            $tplVar = [
                'F_ACTION' => $url_self,
                'KEY' => $key,
                'CONTENT' => '',
                'SHOW_AUTHOR' => ! $accessLevelChecker->isClassicUser(),
                'AUTHOR_MANDATORY' => $currentConfig->commentsAuthorMandatory(),
                'AUTHOR' => '',
                'WEBSITE_URL' => '',
                'SHOW_EMAIL' => ! $accessLevelChecker->isClassicUser() or $userEmailEmpty,
                'EMAIL_MANDATORY' => $currentConfig->commentsEmailMandatory(),
                'EMAIL' => '',
                'SHOW_WEBSITE' => $currentConfig->commentsEnableWebsite(),
            ];

            if ($commentAction === 'reject') {
                $postValues = [
                    'content' => $pictureCommentSubmitRequest->content,
                    'author' => $pictureCommentSubmitRequest->author,
                    'website_url' => $pictureCommentSubmitRequest->websiteUrl,
                    'email' => $pictureCommentSubmitRequest->email,
                ];
                foreach ($postValues as $k => $postValue) {
                    $tplVar[strtoupper($k)] = $postValue !== null ? htmlspecialchars(stripslashes($postValue)) : '';
                }
            }
            $template->assign('comment_add', $tplVar);
        }
        $template->set_filenames([
            'comment_list' => 'comment_list.tpl',
        ]);
        $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
    }
}
