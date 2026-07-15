<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Job\BatchUploadJob;

/**
 * Not attribute-discovered -- see SendNotificationEmailHandler's docblock.
 *
 * Delegates to UploadService::addUploadedFile() (formerly the free
 * function add_uploaded_file() in admin/include/functions_upload.inc.php,
 * deleted in P23 sub-batch 8b-3) -- constructed inline rather than
 * constructor-injected like this class's siblings
 * (GenerateDerivativeHandler/RegenerateAllDerivativesHandler/
 * ReindexImagesHandler), since UploadService is `final` (no subclass test
 * double) with no interface (no mock seam) and its real execution ends
 * with a genuine self-fetchRemote() HTTP call back into the running app
 * to force derivative-cache generation -- only a live Browser-tier
 * context can safely exercise it. Extracting a proper, independently
 * testable service abstraction here is still a P21+-scale refactor (tus
 * upload support), out of this greenfield job-mechanism phase's scope;
 * this fold only needed to keep the call working once the free-function
 * bridge disappeared.
 */
final class BatchUploadHandler
{
    public function __invoke(BatchUploadJob $job): int
    {
        $imageId = new UploadService()
            ->addUploadedFile(
                $job->sourceFilepath,
                $job->originalFilename,
                $job->categories,
                $job->level,
                $job->imageId,
                $job->originalMd5sum,
            );

        // UploadService::addUploadedFile() is declared int|string, but its
        // only two real `return` statements both return $image_id, which
        // is always int by that point -- a real (pre-existing, harmless)
        // over-broad declared type; narrow explicitly (PHPStan can't
        // trace this through the method-call boundary) rather than
        // widening this method's own return type to match it.
        assert(is_int($imageId));

        return $imageId;
    }
}
