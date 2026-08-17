<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Admin\Maintenance\CacheSizeCalculator;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/cache-size` -- `pwg.getCacheSize`'s real replacement,
 * admin only. The Maintenance page's own "calculate cache size" button.
 */
final readonly class CacheSizeController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CacheSizeCalculator $cacheSizeCalculator,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $result = $this->cacheSizeCalculator->calculate();

        return ResponseFactory::json([
            'cacheSize' => $result['cacheSize'],
            'msizes' => $result['msizes'],
            'templatesSize' => $result['templatesSize'],
            'lastDateCalc' => $result['lastDateCalc'],
        ]);
    }
}
