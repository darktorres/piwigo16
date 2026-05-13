<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Exception\AuthException;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final class PictureCommentRenderer
{
    public function __construct(
        private readonly Connection $conn,
        private readonly CommentService $commentService,
        private readonly DateService $dateService,
        private readonly HtmlService $htmlService,
        private readonly PermissionService $permissionService,
        private readonly SessionService $sessionService,
        private readonly UrlService $urlService,
        private readonly Util $util,
    ) {
    }

    public function render(?int $editComment = null): void
    {
        $template = TemplateRegistry::current();
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $picture = is_array($GLOBALS['picture'] ?? null) ? $GLOBALS['picture'] : [];
        $related_categories = is_array($GLOBALS['related_categories'] ?? null) ? $GLOBALS['related_categories'] : [];
        $url_self = is_scalar($GLOBALS['url_self'] ?? null) ? (string) $GLOBALS['url_self'] : '';

        $page['show_comments'] = false;
        foreach ($related_categories as $category) {
            if (is_array($category) && ($category['commentable'] ?? '') == 'true') {
                $page['show_comments'] = true;
                break;
            }
        }

        $comment_action = null;

        if ($page['show_comments'] and isset($_POST['content'])) {
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
                'image_id' => $page['image_id'] ?? null,
            ];

            $post_key = $_POST['key'] ?? '';
            /** @var list<string> $commentErrors */
            $commentErrors = [];
            $comment_action = $this->commentService->insertUserComment($comm, is_string($post_key) ? $post_key : '', $commentErrors);
            foreach ($commentErrors as $err) {
                PageState::current()->addError($err);
            }

            switch ($comment_action) {
                case 'moderate':
                    PageState::current()->addInfo(Lang::t('An administrator must authorize your comment before it is visible.'));
                    // no break
                case 'validate':
                    PageState::current()->addInfo(Lang::t('Your comment has been registered'));
                    break;
                case 'reject':
                    $this->htmlService->setStatusHeader(403);
                    PageState::current()->addError(Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
                    break;
                default:
                    trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
            }

            EventDispatcher::notify('user_comment_insertion', array_merge($comm, ['action' => $comment_action]));
        } elseif (isset($_POST['content'])) {
            $this->htmlService->setStatusHeader(403);
            throw new AuthException('ugly spammer');
        }

        if ($page['show_comments']) {
            if (!$this->permissionService->isAdmin()) {
                $validated_clause = '  AND validated = \'true\'';
            } else {
                $validated_clause = '';
            }

            $pageImageId = $page['image_id'] ?? null;
            $imageId = is_numeric($pageImageId) ? (int) $pageImageId : 0;
            $rowFetch = $this->conn
                ->executeQuery(
                    'SELECT COUNT(*) AS nb_comments FROM ' . Tables::comments() .
                    ' WHERE image_id = ?' . ($validated_clause !== '' ? " AND validated = 'true'" : ''),
                    [$imageId]
                )
                ->fetchAssociative();
            $row = $rowFetch !== false ? $rowFetch : [];

            if (!isset($page['start']) || !is_numeric($page['start'])) {
                $page['start'] = 0;
            }
            $startOffset = (int) $page['start'];
            $nb_comments = is_numeric($row['nb_comments'] ?? null) ? (int) $row['nb_comments'] : 0;

            $navigation_bar = $this->util->createNavigationBar(
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
                    $this->sessionService->setSessionVar('comments_order', is_string($get_comments_order) ? $get_comments_order : '');
                }
                $rawOrder       = $this->sessionService->getSessionVar('comments_order', Config::commentsOrder());
                $comments_order = is_string($rawOrder) ? $rawOrder : Config::commentsOrder();

                $template->assign([
                    'COMMENTS_ORDER_URL' => $this->urlService->addUrlParams($this->urlService->duplicatePictureUrl(), ['comments_order' => ($comments_order == 'ASC' ? 'DESC' : 'ASC')]),
                    'COMMENTS_ORDER_TITLE' => $comments_order == 'ASC' ? Lang::t('Show latest comments first') : Lang::t('Show oldest comments first'),
                ]);

                $query = '
SELECT
    com.id,
    com.author,
    com.author_id,
    u.' . Config::userFields()['email'] . ' AS user_email,
    com.date,
    com.image_id,
    com.website_url,
    com.email,
    com.content,
    com.validated
  FROM ' . Tables::comments() . ' AS com
  LEFT JOIN ' . Tables::users() . ' AS u
    ON u.' . Config::userFields()['id'] . ' = author_id
  WHERE com.image_id = ' . $imageId . '
    ' . $validated_clause . '
  ORDER BY com.date ' . $comments_order . '
  LIMIT ' . Config::nbCommentPage() . ' OFFSET ' . $startOffset . '
;';
                $commentRows = $this->conn
                    ->executeQuery($query)
                    ->fetchAllAssociative();

                foreach ($commentRows as $row) {
                    if ($row['author'] == 'guest') {
                        $row['author'] = Lang::t('guest');
                    }

                    $email = null;
                    if (!empty($row['user_email'])) {
                        $email = $row['user_email'];
                    } elseif (!empty($row['email'])) {
                        $email = $row['email'];
                    }

                    $renderedContent = EventDispatcher::dispatch('render_comment_content', $row['content']);
                    $tpl_comment = [
                        'ID' => $row['id'],
                        'AUTHOR' => EventDispatcher::dispatch('render_comment_author', $row['author']),
                        'DATE' => $this->dateService->formatDate(is_string($row['date'] ?? null) ? $row['date'] : '', ['day_name', 'day', 'month', 'year', 'time']),
                        'CONTENT' => new Html(is_string($renderedContent) ? $renderedContent : ''),
                        'WEBSITE_URL' => $row['website_url'],
                    ];

                    if ($this->permissionService->canManageComment('delete', is_numeric($row['author_id']) ? (int) $row['author_id'] : 0)) {
                        $tpl_comment['U_DELETE'] = $this->urlService->addUrlParams($url_self, [
                            'action' => 'delete_comment',
                            'comment_to_delete' => $row['id'],
                            'pwg_token' => $this->util->getPwgToken(),
                        ]);
                    }
                    if ($this->permissionService->canManageComment('edit', is_numeric($row['author_id']) ? (int) $row['author_id'] : 0)) {
                        $tpl_comment['U_EDIT'] = $this->urlService->addUrlParams($url_self, [
                            'action' => 'edit_comment',
                            'comment_to_edit' => $row['id'],
                        ]);
                        if ($editComment !== null && $row['id'] == $editComment) {
                            $tpl_comment['IN_EDIT'] = true;
                            $key = $this->util->getEphemeralKey(2, (string) $imageId);
                            $tpl_comment['KEY'] = $key;
                            $tpl_comment['CONTENT'] = $row['content'];
                            $tpl_comment['PWG_TOKEN'] = $this->util->getPwgToken();
                            $tpl_comment['U_CANCEL'] = $url_self;
                        }
                    }
                    if ($this->permissionService->isAdmin()) {
                        $tpl_comment['EMAIL'] = $email;

                        if ($row['validated'] != 'true') {
                            $tpl_comment['U_VALIDATE'] = $this->urlService->addUrlParams($url_self, [
                                'action' => 'validate_comment',
                                'comment_to_validate' => $row['id'],
                                'pwg_token' => $this->util->getPwgToken(),
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
                $key = $this->util->getEphemeralKey(3, (string) $imageId);

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
