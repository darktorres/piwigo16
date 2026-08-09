<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

use RuntimeException;

/**
 * Replaces the 17 real die() calls across
 * ImageGd.php/PwgImage.php/ImageExtImagick.php/Admin\Upload\UploadService.php
 * (mid-request image-processing/upload failures -- corrupt image, missing
 * library, unsupported/forbidden file type, directory creation/write
 * failure). A plain throw, not a Piwigo\Http\ResponseReadyException: that
 * class is deliberately for *expected* control flow (redirects, 403s,
 * 404s) that must never reach Sentry (see its own docblock) -- these
 * failures are genuine unexpected errors that should. Piwigo\Http\
 * Middleware\ExceptionHandlerMiddleware already catches, logs, and
 * Sentry-reports any \Throwable that reaches it for a real HTTP request;
 * Symfony Messenger's own consumer loop (Job\MessengerFactory) does the
 * same for the job-queue callers (Job\BatchUploadJob/BatchUploadHandler).
 * Both are strict improvements over die(), which produced neither logging
 * nor Sentry visibility and skipped every pending `finally` block.
 */
final class ImageProcessingException extends RuntimeException {}
