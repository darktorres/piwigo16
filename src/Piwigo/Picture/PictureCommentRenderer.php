<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
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
     * @param list<array<string, string|null>> $related_categories
     */
    public function render(?int $editCommentId, int $imageId, int $start, UrlServiceInterface $urlService, array $related_categories, string $url_self): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $commentRepository = new CommentRepository(DbConnection::build());
        $commentService = new CommentService($commentRepository, new EphemeralKeyService(), new MailService(), new HtmlService(), $urlService);

        $commentAction = null;

        // the picture is commentable if it belongs at least to one category
        // which is commentable
        $showComments = false;
        foreach ($related_categories as $category) {
            if ($category['commentable'] === 'true') {
                $showComments = true;
                break;
            }
        }

        if ($showComments and isset($_POST['content'])) {
            if (\Piwigo\Auth\AccessControl::isAGuest() and ! \Piwigo\Config\Config::commentsForall()) {
                throw new ResponseReadyException(ResponseFactory::text('Session expired'));
            }

            $postAuthor = $_POST['author'] ?? null;
            // isset($_POST['content']) was already checked by the enclosing if().
            $postContent = $_POST['content'];
            $postWebsiteUrl = $_POST['website_url'] ?? null;
            $postEmail = $_POST['email'] ?? null;

            $comm = [
                'author' => is_string($postAuthor) && $postAuthor !== '' && $postAuthor !== '0' ? trim($postAuthor) : '',
                'content' => is_string($postContent) && $postContent !== '' && $postContent !== '0' ? trim($postContent) : '',
                'website_url' => is_string($postWebsiteUrl) && $postWebsiteUrl !== '' && $postWebsiteUrl !== '0' ? trim($postWebsiteUrl) : '',
                'email' => is_string($postEmail) && $postEmail !== '' && $postEmail !== '0' ? trim($postEmail) : '',
                'image_id' => $imageId,
            ];

            $postKey = $_POST['key'] ?? null;
            // insertComment() overwrites $commentErrors unconditionally as its
            // very first statement, so whatever was previously there is
            // never actually read by it; a fresh array is passed and the
            // result is written back below.
            $commentErrors = [];
            $commentAction = $commentService->insertComment($comm, is_string($postKey) ? $postKey : '', $commentErrors);
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
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify(
                'user_comment_insertion',
                array_merge($comm, [
                    'action' => $commentAction,
                ])
            );
        } elseif (isset($_POST['content'])) {
            throw new ResponseReadyException(ResponseFactory::text('ugly spammer', 403));
        }

        if (! $showComments) {
            return;
        }

        $onlyValidated = ! \Piwigo\Auth\AccessControl::isAdmin();

        // number of comments for this picture
        $nbComments = $commentRepository->countForImage($imageId, $onlyValidated);

        // navigation bar creation
        $nbCommentPage = \Piwigo\Config\Config::nbCommentPage();

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
            $getCommentsOrder = $_GET['comments_order'] ?? null;
            if (is_string($getCommentsOrder) && $getCommentsOrder !== '' && $getCommentsOrder !== '0' && in_array(strtoupper($getCommentsOrder), ['ASC', 'DESC'], true)) {
                SessionService::get()->setSessionVar('comments_order', $getCommentsOrder);
            }
            $commentsOrder = SessionService::get()->getSessionVar('comments_order', \Piwigo\Config\Config::commentsOrder());
            $commentsOrder = is_string($commentsOrder) ? $commentsOrder : 'ASC';

            $template->assign([
                'COMMENTS_ORDER_URL' => $urlService->addUrlParams($urlService->duplicatePictureUrl(), [
                    'comments_order' => ($commentsOrder === 'ASC' ? 'DESC' : 'ASC'),
                ]),
                'COMMENTS_ORDER_TITLE' => $commentsOrder === 'ASC' ? Lang::t('Show latest comments first') : Lang::t('Show oldest comments first'),
            ]);

            // \Piwigo\Config\Config::userFields() maps generic field names to actual DB
            // column names; it is always set by config_default.inc.php.
            $userFields = \Piwigo\Config\Config::userFields();
            $userFieldEmail = $userFields['email'] ?? null;
            $userFieldEmail = is_string($userFieldEmail) ? $userFieldEmail : '';
            $userFieldId = $userFields['id'] ?? null;
            $userFieldId = is_string($userFieldId) ? $userFieldId : '';

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
                if ($row['author'] === 'guest') {
                    $row['author'] = Lang::t('guest');
                }

                $email = null;
                $rowUserEmail = $row['user_email'] ?? null;
                $rowEmail = $row['email'] ?? null;
                if (is_string($rowUserEmail) && $rowUserEmail !== '' && $rowUserEmail !== '0') {
                    $email = $rowUserEmail;
                } elseif (is_string($rowEmail) && $rowEmail !== '' && $rowEmail !== '0') {
                    $email = $rowEmail;
                }

                // com.date is NOT NULL in the schema (default
                // '1970-01-01 00:00:00'), so a fetched row always carries a
                // real date string.
                assert(is_string($row['date']));

                $tplComment =
                  [
                      'ID' => $row['id'],
                      'AUTHOR' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_author', $row['author']),
                      'DATE' => \Piwigo\Core\DateHelper::formatDate($row['date'], ['day_name', 'day', 'month', 'year', 'time']),
                      'CONTENT' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_comment_content', $row['content']),
                      'WEBSITE_URL' => $row['website_url'],
                  ];

                // com.author_id allows NULL (anonymous/guest comments); no
                // real user id is ever negative, so -1 is a safe
                // "never matches" sentinel.
                $commentAuthorId = is_numeric($row['author_id']) ? (int) $row['author_id'] : -1;

                if (\Piwigo\Auth\AccessControl::canManageComment('delete', $commentAuthorId)) {
                    $tplComment['U_DELETE'] = $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $row['id'],
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
                            'comment_to_edit' => $row['id'],
                        ]
                    );
                    if ($editCommentId !== null and is_numeric($row['id']) and (int) $row['id'] === $editCommentId) {
                        $tplComment['IN_EDIT'] = true;
                        $key = new \Piwigo\Auth\EphemeralKeyService()
                            ->generate(2, (string) $imageId);
                        $tplComment['KEY'] = $key;
                        $tplComment['CONTENT'] = $row['content'];
                        $tplComment['PWG_TOKEN'] = new \Piwigo\Csrf\CsrfService()->getToken();
                        $tplComment['U_CANCEL'] = $url_self;
                    }
                }
                if (\Piwigo\Auth\AccessControl::isAdmin()) {
                    $tplComment['EMAIL'] = $email;

                    if ($row['validated'] !== 'true') {
                        $tplComment['U_VALIDATE'] = $urlService->addUrlParams(
                            $url_self,
                            [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $row['id'],
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
        if (\Piwigo\Auth\AccessControl::isAGuest() and ! \Piwigo\Config\Config::commentsForall()) {
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
                'AUTHOR_MANDATORY' => \Piwigo\Config\Config::commentsAuthorMandatory(),
                'AUTHOR' => '',
                'WEBSITE_URL' => '',
                'SHOW_EMAIL' => ! \Piwigo\Auth\AccessControl::isClassicUser() or $userEmailEmpty,
                'EMAIL_MANDATORY' => \Piwigo\Config\Config::commentsEmailMandatory(),
                'EMAIL' => '',
                'SHOW_WEBSITE' => \Piwigo\Config\Config::commentsEnableWebsite(),
            ];

            if ($commentAction === 'reject') {
                foreach (['content', 'author', 'website_url', 'email'] as $k) {
                    $postValue = $_POST[$k] ?? null;
                    $tplVar[strtoupper($k)] = is_string($postValue) ? htmlspecialchars(stripslashes($postValue)) : '';
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
