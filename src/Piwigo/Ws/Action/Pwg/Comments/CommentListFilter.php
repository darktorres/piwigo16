<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

/**
 * `pwg.comments.getList` `status` filter — all comments, only pending
 * (validated=0), or only validated (validated=1).
 */
enum CommentListFilter: string
{
    case All       = 'all';
    case Pending   = 'pending';
    case Validated = 'validated';
}
