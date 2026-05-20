<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/**
 * Outcome of running a comment through {@see CommentService}'s
 * spam/anti-flood/validation pipeline. Drives the success-message
 * branch in PictureCommentRenderer, AddCommentHandler, and
 * CommentsController.
 */
enum CommentModerationAction: string
{
    case Reject   = 'reject';
    case Moderate = 'moderate';
    case Validate = 'validate';
}
