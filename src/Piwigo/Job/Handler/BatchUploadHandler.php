<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Job\BatchUploadJob;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 *
 * Delegates to add_uploaded_file() (admin/include/functions_upload.inc.php)
 * -- a free function, not a class, since that's genuinely where the real
 * upload-commit logic (thumbnail generation, DB insert, category
 * assignment, metadata sync) already lives; extracting it into a proper
 * service is a P21+-scale refactor (tus upload support), out of this
 * greenfield job-mechanism phase's scope. A real console worker process
 * (P21+) would need to load that file before consuming this job.
 */
final class BatchUploadHandler
{
    // add_uploaded_file() is declared int|string, but its only two real
    // `return` statements both return $image_id, which is always int by
    // that point -- PHPStan traces this, a real (pre-existing, harmless)
    // over-broad declared type on that free function; narrowed here
    // rather than widening this method's own return type to match it.
    public function __invoke(BatchUploadJob $job): int
    {
        return add_uploaded_file(
            $job->sourceFilepath,
            $job->originalFilename,
            $job->categories,
            $job->level,
            $job->imageId,
            $job->originalMd5sum,
        );
    }
}
