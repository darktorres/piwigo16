<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\GeoIp\GeoIpLookupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/geoip?ip=<ip>` -- real replacement for jquery.geoip.js's
 * client-side call to the long-dead http://freegeoip.net/json/<ip>
 * (docs/PLAN.md P49-B group 1's own finding), admin only (both real
 * callers, history.ts's connection log and ratings/user.ts's anonymous-
 * rater tooltip, are admin-only pages).
 *
 * Always 200: `{"available": false}` is an expected "nothing to show"
 * result (database not downloaded yet, or the IP has no match), not an
 * error -- a 404 here would make every ordinary cache-miss hover look
 * like a client bug.
 */
final readonly class GeoIpLookupController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private GeoIpLookupService $geoIpLookupService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $ip = IpAddress::tryFrom($query['ip'] ?? null);
        if ($ip === null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid or missing ip.');
        }

        $result = $this->geoIpLookupService->lookup($ip->value);
        if ($result === null) {
            return ResponseFactory::json([
                'available' => false,
            ]);
        }

        return ResponseFactory::json([
            'available' => true,
            'fullName' => $result->fullName(),
            'latitude' => $result->latitude,
            'longitude' => $result->longitude,
        ]);
    }
}
