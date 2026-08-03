<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Event\User\UserCommentInsertion;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionService;

/**
 * Renders the picture page's comment list + add/edit form. Ported from
 * include/picture_comment.inc.php.
 *
 * Real bug fixed during this port: the original file read a bare
 * `isset($edit_comment)` / `$edit_comment`, relying on PictureController and
 * this file sharing one real top-level PHP scope -- true when picture.php
 * was a plain top-level script, silently broken once PictureController
 * became a class method whose "edit_comment" action branch sets
 * $edit_comment as a real method-local variable, invisible to the
 * LegacyRenderCapture closure this file is include()'d from (not in its
 * use() list or its own `global` declarations). Clicking "Edit" on your own
 * comment silently did nothing -- no prefill, no IN_EDIT flag. Fixed by
 * threading the value through explicitly as $editCommentId, exactly like
 * every other closure-local value already passed into this render() call.
 * This is a pure propagation fix, not an authorization change:
 * PictureController's own can_manage_comment('edit', $author_id) check is
 * what decides whether a real id ever reaches this method, and this file's
 * own can_manage_comment('edit', $comment_author_id) check (below) is what
 * decides whether any given comment row honors it -- both already existed
 * and are unchanged.
 *
 * Workstream C3c: render()'s 2 reject paths ("Session expired"/"ugly
 * spammer") now throw Piwigo\Http\ResponseReadyException instead of
 * die()ing -- caught by Http\Middleware\ControllerInvokerMiddleware, same
 * as every other controller (Workstream C3a/C3c). "Session expired" keeps
 * its original (no explicit setStatusHeader() call ever preceded it) 200
 * status; "ugly spammer" keeps its explicit 403. Piwigo\Controller\
 * PictureController, this class's one real caller, dropped its own
 * LegacyRenderCapture wrapper in the same commit, so both die() sites
 * used to skip that closure's try/finally (die()/exit() skip finally
 * entirely) and rely on PHP's own default output-buffer-flush-on-exit()
 * behavior to still send whatever partial HTML had accumulated --
 * throwing instead means a clean reject response with no partial HTML,
 * matching how Controller\ActionController::doError() already works.
 */
final class PictureCommentRenderer
{
    /**
     * Legacy Coupling Retirement Track A batch A5.2e: $imageId/$start are
     * explicit params instead of `global $page['image_id']`/`['start']`
     * -- the one real caller (PictureController) already tracks $imageId
     * as its own local variable, and $start is the confirmed-real
     * collision with the gallery grid's own start offset (this file's
     * comment-list pagination genuinely reuses the same value, see the
     * nav-bar URL below stripping+reusing it), so both come from the
     * caller directly rather than a registry read.
     */
    /**
     * @param list<array<string, mixed>> $related_categories row shape is
     *   mixed, not uniformly string|null: `commentable` is this table's
     *   real tinyint column (int|bool depending on the driver), `id` a
     *   native DBAL int -- only `uppercats`/`status`/`global_rank` are
     *   genuinely string|null.
     */
    public function render(?CommentId $editCommentId, int $imageId, int $start, UrlServiceInterface $urlService, array $related_categories, string $url_self, SessionService $sessionService, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $commentRepository = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Comment\CommentEntity::class);
        $commentService = new CommentService($commentRepository, new EphemeralKeyService(), new MailService(), new HtmlService(), $urlService, $eventDispatcher);

        $commentAction = null;

        $pictureCommentSubmitRequest = Request\PictureCommentSubmitRequest::fromGlobals();

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
            if (\Piwigo\Auth\AccessControl::isAGuest() and ! \Piwigo\Config\CurrentConfig::commentsForall()) {
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
            // very first statement, so whatever was previously there is
            // never actually read by it; a fresh array is passed and the
            // result is written back below.
            $commentErrors = [];
            $commentAction = $commentService->insertComment($comm, $postKey ?? '', $commentErrors);
            \Piwigo\Core\PageState::current()->errors = $commentErrors;

            // Narrowed once into local variables and written back after the
            // switch, so the case bodies below don't re-read PageState
            // directly (switch branches lose property narrowing in this
            // codebase, see the other L10 fixes for the same pattern).
            $commentInfos = \Piwigo\Core\PageState::current()->infos;

            switch ($commentAction) {
                case 'moderate':
                    $commentInfos[] = Lang::t('An administrator must authorize your comment before it is visible.');
                    // no break
                case 'validate':
                    $commentInfos[] = Lang::t('Your comment has been registered');
                    break;
                case 'reject':
                    new HtmlService()
                        ->setStatusHeader(403);
                    $commentErrors[] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
                    break;
                default:
                    trigger_error('Invalid comment action ' . $commentAction, E_USER_WARNING);
            }

            \Piwigo\Core\PageState::current()->infos = $commentInfos;
            \Piwigo\Core\PageState::current()->errors = $commentErrors;

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

        $onlyValidated = ! \Piwigo\Auth\AccessControl::isAdmin();

        // number of comments for this picture
        $nbComments = $commentRepository->countForImage($imageId, $onlyValidated);

        // navigation bar creation
        $nbCommentPage = \Piwigo\Config\CurrentConfig::nbCommentPage();

        $navigationBar = new \Piwigo\Core\PaginationService()
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
            if ($getCommentsOrder !== null && $getCommentsOrder !== '' && $getCommentsOrder !== '0' && in_array(strtoupper($getCommentsOrder), ['ASC', 'DESC'], true)) {
                $sessionService->setSessionVar('comments_order', $getCommentsOrder);
            }
            $commentsOrder = $sessionService->getSessionVar('comments_order', \Piwigo\Config\CurrentConfig::commentsOrder());
            $commentsOrder = is_string($commentsOrder) ? $commentsOrder : 'ASC';

            $template->assign([
                'COMMENTS_ORDER_URL' => $urlService->addUrlParams($urlService->duplicatePictureUrl(), [
                    'comments_order' => ($commentsOrder === 'ASC' ? 'DESC' : 'ASC'),
                ]),
                'COMMENTS_ORDER_TITLE' => $commentsOrder === 'ASC' ? Lang::t('Show latest comments first') : Lang::t('Show oldest comments first'),
            ]);

            $userFields = \Piwigo\Config\CurrentConfig::userFields();
            $userFieldEmail = $userFields['email'];
            $userFieldId = $userFields['id'];

            $rows = $commentRepository->findForImage(
                $imageId,
                $onlyValidated,
                $userFieldId,
                $userFieldEmail,
                $commentsOrder,
                $nbCommentPage,
                $start
            );

            foreach ($rows as $row) {
                $author = $row->author === 'guest' ? Lang::t('guest') : $row->author;

                $email = null;
                if ($row->userEmail !== null && $row->userEmail !== '' && $row->userEmail !== '0') {
                    $email = $row->userEmail;
                } elseif ($row->email !== null && $row->email !== '' && $row->email !== '0') {
                    $email = $row->email;
                }

                // com.date is nullable now (Comment domain Stage 1a
                // dropped its 1970-01-01 sentinel default) -- every real
                // insert still sets it explicitly (CommentRepository::
                // insert()), but formatDate()'s own `false` "no date"
                // sentinel is the correct fallback, not an assert(),
                // matching PwgComments::getList()'s own identical guard
                // for this same column.
                $rowDate = $row->date ?? false;

                $authorEvent = $eventDispatcher->dispatchChange(new RenderCommentAuthor($author ?? ''));

                $contentEvent = $eventDispatcher->dispatchChange(new RenderCommentContent($row->content ?? ''));

                $tplComment =
                  [
                      'ID' => $row->id->value,
                      'AUTHOR' => $authorEvent->commentAuthor,
                      'DATE' => \Piwigo\Core\DateHelper::formatDate($rowDate, ['day_name', 'day', 'month', 'year', 'time']),
                      'CONTENT' => $contentEvent->commentContent,
                      'WEBSITE_URL' => $row->websiteUrl,
                  ];

                // com.author_id allows NULL (anonymous/guest comments); no
                // real user id is ever negative, so -1 is a safe
                // "never matches" sentinel.
                $commentAuthorId = $row->authorId ?? -1;

                if (\Piwigo\Auth\AccessControl::canManageComment('delete', $commentAuthorId)) {
                    $tplComment['U_DELETE'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $row->id->value,
                            'pwg_token' => new \Piwigo\Csrf\CsrfService()
                                ->getToken(),
                        ]
                    );
                }
                if (\Piwigo\Auth\AccessControl::canManageComment('edit', $commentAuthorId)) {
                    $tplComment['U_EDIT'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'edit_comment',
                            'comment_to_edit' => $row->id->value,
                        ]
                    );
                    if ($editCommentId !== null and $row->id->equals($editCommentId)) {
                        $tplComment['IN_EDIT'] = true;
                        $key = new \Piwigo\Auth\EphemeralKeyService()
                            ->generate(2, (string) $imageId);
                        $tplComment['KEY'] = $key;
                        $tplComment['CONTENT'] = $row->content;
                        $tplComment['PWG_TOKEN'] = new \Piwigo\Csrf\CsrfService()->getToken();
                        $tplComment['U_CANCEL'] = $url_self;
                    }
                }
                if (\Piwigo\Auth\AccessControl::isAdmin()) {
                    $tplComment['EMAIL'] = $email;

                    if (! $row->validated) {
                        $tplComment['U_VALIDATE'] = $urlService->addUrlParams(
                            $url_self,
                            [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $row->id->value,
                                'pwg_token' => new \Piwigo\Csrf\CsrfService()
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
        if (\Piwigo\Auth\AccessControl::isAGuest() and ! \Piwigo\Config\CurrentConfig::commentsForall()) {
            $showAddCommentForm = false;
        }

        if ($showAddCommentForm) {
            $key = new \Piwigo\Auth\EphemeralKeyService()
                ->generate(3, (string) $imageId);

            $userEmail = \Piwigo\Users\CurrentUser::get()->email;
            $userEmailEmpty = $userEmail === '' || $userEmail === '0';

            $tplVar = [
                'F_ACTION' => $url_self,
                'KEY' => $key,
                'CONTENT' => '',
                'SHOW_AUTHOR' => ! \Piwigo\Auth\AccessControl::isClassicUser(),
                'AUTHOR_MANDATORY' => \Piwigo\Config\CurrentConfig::commentsAuthorMandatory(),
                'AUTHOR' => '',
                'WEBSITE_URL' => '',
                'SHOW_EMAIL' => ! \Piwigo\Auth\AccessControl::isClassicUser() or $userEmailEmpty,
                'EMAIL_MANDATORY' => \Piwigo\Config\CurrentConfig::commentsEmailMandatory(),
                'EMAIL' => '',
                'SHOW_WEBSITE' => \Piwigo\Config\CurrentConfig::commentsEnableWebsite(),
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
