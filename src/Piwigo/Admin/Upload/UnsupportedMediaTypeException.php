<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use RuntimeException;

/**
 * A client input error (the uploaded file's real MIME type doesn't
 * match its extension) rather than a genuine server-side failure --
 * a sibling of ImageProcessingException (which is `final`, so this can't
 * extend it), not a subclass. Every ImageProcessingException site keeps
 * propagating uncaught to ExceptionHandlerMiddleware (a real 500 +
 * Sentry report, correctly, since those are unexpected server errors).
 * `TusUploadCompletionService::completePhoto()` catches this one
 * specifically and maps it to a 415 problem+json response instead.
 */
final class UnsupportedMediaTypeException extends RuntimeException {}
