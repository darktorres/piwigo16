<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the image domain's own data-touching function from
 * `include/functions_picture.inc.php` -- the other 5 ported functions
 * (slideshow param encode/decode, PDF page counting) are pure computation
 * with no DB access of their own and live on {@see ImageService} instead,
 * same Repository=DB-only/Service=business-logic split as every other P19
 * domain.
 */
final class ImageRepository extends AbstractRepository
{
    /**
     * Deliberately avoids bumping `lastmodified` (the original's own SQL
     * comment, preserved) -- an image's "last modified" timestamp should
     * reflect real edits, not visit counting.
     */
    public function incrementVisitCounter(int $imageId): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET hit = hit + 1, lastmodified = lastmodified WHERE id = ?',
            [$imageId]
        );
    }
}
