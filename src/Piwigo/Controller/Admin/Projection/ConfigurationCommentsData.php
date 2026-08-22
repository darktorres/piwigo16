<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'comments'` tab's own display data, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'comments'` case. Every field here is a fixed, statically-known key
 * (11 of them come from `checkboxValue()`'s own literal `match` arms
 * before this conversion, confirmed as real bool `CurrentConfig`
 * properties, not a genuinely dynamic bag) -- `configuration_comments.latte`
 * still reads them via `$comments['key']` (through
 * {@see ConfigurationCommentsView}'s own array-typed `$comments`), so
 * `toArray()` reproduces that exact shape.
 */
final readonly class ConfigurationCommentsData
{
    /**
     * @param array<string, string> $commentsOrderOptions
     */
    public function __construct(
        public int $nbCommentsPage,
        public string $commentsOrder,
        public array $commentsOrderOptions,
        public bool $activateComments,
        public bool $commentsForall,
        public bool $commentsValidation,
        public bool $emailAdminOnComment,
        public bool $emailAdminOnCommentValidation,
        public bool $userCanDeleteComment,
        public bool $userCanEditComment,
        public bool $emailAdminOnCommentEdition,
        public bool $emailAdminOnCommentDeletion,
        public bool $commentsAuthorMandatory,
        public bool $commentsEmailMandatory,
        public bool $commentsEnableWebsite,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'NB_COMMENTS_PAGE' => $this->nbCommentsPage,
            'comments_order' => $this->commentsOrder,
            'comments_order_options' => $this->commentsOrderOptions,
            'activate_comments' => $this->activateComments,
            'comments_forall' => $this->commentsForall,
            'comments_validation' => $this->commentsValidation,
            'email_admin_on_comment' => $this->emailAdminOnComment,
            'email_admin_on_comment_validation' => $this->emailAdminOnCommentValidation,
            'user_can_delete_comment' => $this->userCanDeleteComment,
            'user_can_edit_comment' => $this->userCanEditComment,
            'email_admin_on_comment_edition' => $this->emailAdminOnCommentEdition,
            'email_admin_on_comment_deletion' => $this->emailAdminOnCommentDeletion,
            'comments_author_mandatory' => $this->commentsAuthorMandatory,
            'comments_email_mandatory' => $this->commentsEmailMandatory,
            'comments_enable_website' => $this->commentsEnableWebsite,
        ];
    }
}
