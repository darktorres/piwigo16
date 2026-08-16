<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Override;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsInitializer;

/**
 * `reflection.getMethodList` -- lists every non-hidden registered WS
 * method. `WsInitializer::init()` is safe to call a second time mid-request
 * (memoization guard skips its own once-only side effects on repeat calls
 * -- see its own docblock); this is that guard's own justification in
 * practice, since `Server::run()` already called it once before
 * dispatching here.
 */
final readonly class GetMethodListHandler implements WsAction
{
    public function __construct(
        private WsInitializer $wsInitializer,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{methods: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $server = $this->wsInitializer->init();

        return [
            'methods' => new NamedArray($server->listVisibleMethodNames(), 'method'),
        ];
    }
}
