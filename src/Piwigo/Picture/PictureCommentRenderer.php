<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
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
 */
final class PictureCommentRenderer
{
    public function render(?int $editCommentId): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();
        /**
         * Set by PictureController, right before this call.
         *
         * @var list<array<string, string|null>>
         */
        global $related_categories;
        /**
         * @var string
         */
        global $url_self;

        $commentRepository = new CommentRepository(DbConnection::build());
        $commentService = new CommentService($commentRepository, new EphemeralKeyService(), new MailService(), new HtmlService());

        $commentAction = null;

        // $page['image_id'] is int|numeric-string (include/section_init.inc.php
        // sets it from a URL token via is_numeric(), possibly re-resolved by
        // PictureController).
        $imageId = $page['image_id'];
        $imageId = is_numeric($imageId) ? (int) $imageId : 0;

        // the picture is commentable if it belongs at least to one category
        // which is commentable
        $page['show_comments'] = false;
        foreach ($related_categories as $category) {
            if ($category['commentable'] === 'true') {
                $page['show_comments'] = true;
                break;
            }
        }

        if ($page['show_comments'] and isset($_POST['content'])) {
            if (\Piwigo\Auth\AccessControl::isAGuest() and ! \Piwigo\Config\Config::commentsForall()) {
                die('Session expired');
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
            // very first statement, so whatever was previously in
            // $page['errors'] is never actually read by it; a fresh array is
            // passed and the result is written back below.
            $commentErrors = [];
            $commentAction = $commentService->insertComment($comm, is_string($postKey) ? $postKey : '', $commentErrors);
            $page['errors'] = $commentErrors;

            // Narrowed once into local variables and written back after the
            // switch, so the case bodies below don't re-read the $page[...]
            // offsets directly (switch branches lose array-offset narrowing
            // in this codebase, see the other L10 fixes for the same pattern).
            $commentInfos = is_array($page['infos'] ?? null) ? $page['infos'] : [];

            switch ($commentAction) {
                case 'moderate':
                    $commentInfos[] = l10n('An administrator must authorize your comment before it is visible.');
                    // no break
                case 'validate':
                    $commentInfos[] = l10n('Your comment has been registered');
                    break;
                case 'reject':
                    new HtmlService()
                        ->setStatusHeader(403);
                    $commentErrors[] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
                    break;
                default:
                    trigger_error('Invalid comment action ' . $commentAction, E_USER_WARNING);
            }

            $page['infos'] = $commentInfos;
            $page['errors'] = $commentErrors;

            // allow plugins to notify what's going on
            trigger_notify(
                'user_comment_insertion',
                array_merge($comm, [
                    'action' => $commentAction,
                ])
            );
        } elseif (isset($_POST['content'])) {
            new HtmlService()
                ->setStatusHeader(403);
            die('ugly spammer');
        }

        if (! $page['show_comments']) {
            return;
        }

        $onlyValidated = ! \Piwigo\Auth\AccessControl::isAdmin();

        // number of comments for this picture
        $nbComments = $commentRepository->countForImage($imageId, $onlyValidated);

        // navigation bar creation
        if (! isset($page['start'])) {
            $page['start'] = 0;
        }
        $start = $page['start'];
        $start = is_numeric($start) ? (int) $start : 0;

        $nbCommentPage = \Piwigo\Config\Config::nbCommentPage();

        $navigationBar = new \Piwigo\Core\PaginationService()
            ->createNavigationBar(duplicate_picture_url([], ['start']), $nbComments, $start, $nbCommentPage, true);

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
                'COMMENTS_ORDER_URL' => add_url_params(duplicate_picture_url(), [
                    'comments_order' => ($commentsOrder === 'ASC' ? 'DESC' : 'ASC'),
                ]),
                'COMMENTS_ORDER_TITLE' => $commentsOrder === 'ASC' ? l10n('Show latest comments first') : l10n('Show oldest comments first'),
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
                    $row['author'] = l10n('guest');
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
                      'AUTHOR' => trigger_change('render_comment_author', $row['author']),
                      'DATE' => \Piwigo\Core\DateHelper::formatDate($row['date'], ['day_name', 'day', 'month', 'year', 'time']),
                      'CONTENT' => trigger_change('render_comment_content', $row['content']),
                      'WEBSITE_URL' => $row['website_url'],
                  ];

                // com.author_id allows NULL (anonymous/guest comments); no
                // real user id is ever negative, so -1 is a safe
                // "never matches" sentinel.
                $commentAuthorId = is_numeric($row['author_id']) ? (int) $row['author_id'] : -1;

                if (\Piwigo\Auth\AccessControl::canManageComment('delete', $commentAuthorId)) {
                    $tplComment['U_DELETE'] = add_url_params(
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
                    $tplComment['U_EDIT'] = add_url_params(
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
                        $tplComment['U_VALIDATE'] = add_url_params(
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
