<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use Piwigo\Admin\Upload\UnsupportedMediaTypeException;
use Piwigo\Ws\WsAction;

/**
 * Always throws, proving invoke()'s central
 * UnsupportedMediaTypeException-to-415 conversion.
 */
final class ServerTestFakeWsActionThrowsUnsupportedMediaType implements WsAction
{
    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params): never
    {
        throw new UnsupportedMediaTypeException('Wrong file type');
    }
}
