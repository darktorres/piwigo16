<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Event\UserCommentCheck;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own
 * `UserCommentCheck` registration -- this registration has to live
 * somewhere that always executes. `RequestBootstrap` constructs this
 * listener explicitly (not via container autowiring) so `$commentService`
 * reuses the request's own shared `Connection` instead of building a
 * separate one.
 */
final readonly class CommentSpamListener implements ListenerInterface
{
    public function __construct(
        private CommentService $commentService,
    ) {}

    #[Override]
    public function subscribedEvents(): array
    {
        return [
            UserCommentCheck::class => $this->onUserCommentCheck(...),
        ];
    }

    public function onUserCommentCheck(UserCommentCheck $event): UserCommentCheck
    {
        return $this->commentService->checkForSpam($event);
    }
}
