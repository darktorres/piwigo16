<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * web-vitals RUM beacon sink (docs/PLAN.md P1, item 11b) --
 * build/vitals.ts POSTs one JSON body per Core Web Vitals metric via
 * navigator.sendBeacon(), this logs it as structured JSON on the Monolog
 * "app" channel ($logger resolves there per config/container.php, same
 * binding ExceptionHandlerMiddleware already uses). Log-only for this pass
 * (see the plan's own Step 3.4 scope note) -- a p75-aggregating admin
 * dashboard is tracked as a separate follow-up once the log data's real
 * shape is known.
 *
 * A malformed or missing body is silently ignored (still 204) rather than
 * ever surfacing an error -- sendBeacon() is fire-and-forget from a real
 * visitor's browser; there is no user-facing failure mode to report to.
 */
final readonly class VitalsController implements ControllerInterface
{
    private const array KNOWN_METRICS = ['CLS', 'FCP', 'INP', 'LCP', 'TTFB'];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $metric = $this->parseMetric((string) $request->getBody());
        if ($metric !== null) {
            $this->logger->info('web_vitals.metric', $metric);
        }

        return ResponseFactory::text('', 204);
    }

    /**
     * @return array{name: string, value: float, id: string, rating: string, url: string}|null
     */
    private function parseMetric(string $rawBody): ?array
    {
        if ($rawBody === '') {
            return null;
        }

        try {
            $data = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $name = $data['name'] ?? null;
        $value = $data['value'] ?? null;
        $id = $data['id'] ?? null;
        $rating = $data['rating'] ?? null;
        $url = $data['url'] ?? null;

        if (! is_string($name) || ! in_array($name, self::KNOWN_METRICS, true)) {
            return null;
        }
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        if (! is_string($id) || ! is_string($rating) || ! is_string($url)) {
            return null;
        }

        return [
            'name' => $name,
            'value' => (float) $value,
            'id' => $id,
            'rating' => $rating,
            'url' => $url,
        ];
    }
}
