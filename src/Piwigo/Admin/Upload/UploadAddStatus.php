<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

/**
 * Whether an upload created a new image row (Add) or matched an
 * existing one by md5sum and updated it in place (Update). The string
 * value rides on the WS upload-handler JSON response as `add_status`.
 */
enum UploadAddStatus: string
{
    case Add    = 'add';
    case Update = 'update';
}
