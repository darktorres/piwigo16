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
 * reads them as properties directly (P58-A).
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
}
