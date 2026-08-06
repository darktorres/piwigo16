<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Comment\CommentRepository` is L2bExtendedDomain;
 * `Category\CategoryDefaultRenderer` (L2aCoreDomain) constructor-injects
 * this instead of depending on the concrete class directly, per
 * deptrac.yaml's ruleset -- same shape as `ActivityLoggerInterface`/
 * `FilterUpdaterInterface`. `CommentRepository implements` it; bound in
 * `config/container.php`.
 */
interface CommentCounterInterface
{
    /**
     * @param  list<int|string>  $imageIds
     * @return array<int, int> keyed by image id -- PHP canonicalises a
     *   numeric-string array key back to an int key, so this is always
     *   int-keyed at runtime regardless of $imageIds' own element types.
     */
    public function countValidatedByImageIds(array $imageIds): array;
}
