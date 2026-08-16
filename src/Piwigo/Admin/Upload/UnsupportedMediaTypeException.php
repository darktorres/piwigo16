<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use RuntimeException;

/**
 * The one former ImageProcessingException site (see that class's own
 * docblock) that's a client input error (the uploaded file's real MIME
 * type doesn't match its extension) rather than a genuine server-side
 * failure (missing library, disk write failure, corrupt image data) --
 * a sibling of ImageProcessingException (which is `final`, so this can't
 * extend it), not a subclass. Every other ImageProcessingException site
 * keeps propagating uncaught to ExceptionHandlerMiddleware (a real 500 +
 * Sentry report, correctly, since those are unexpected server errors).
 * Server::invoke() catches this specific class centrally and maps it to
 * a 415 WsErrorResponse, the same response every real WS caller of
 * UploadService::addUploadedFile() already got today when it still took
 * a `?Server $service` parameter to special-case this one check itself.
 */
final class UnsupportedMediaTypeException extends RuntimeException {}
