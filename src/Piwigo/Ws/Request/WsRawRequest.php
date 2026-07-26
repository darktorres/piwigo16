<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated request shape for `PwgRestRequestHandler::handleRequest()` --
 * P27/SEC-40 Request DTO. This is the sole real site where the REST WS
 * transport converts raw `$_POST`/`$_GET` into the `method` name +
 * `$params` bag every `Ws\*` method receives -- analogous to
 * `Piwigo\Http\RequestFactory::fromGlobals()`'s own sole legitimate
 * superglobal read for PSR-7 construction. Downstream `$params` values
 * are still coerced per-parameter by `PwgServer::checkParamType()` against
 * each method's own `WsParamType`/`WsParamFlag` signature (a real,
 * already-existing validation layer, just not `InputValidator`-based) --
 * this DTO only extracts the raw method name + parameter bag, faithfully
 * matching the original inline logic (special-cased `format`/`method`
 * keys, `$_GET['method']` fallback when POST didn't carry one, `''`/`'0'`
 * both treated as "no method given").
 */
final readonly class WsRawRequest
{
    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        public ?string $method,
        public array $params,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_POST, $_GET, \Piwigo\Ws\PwgServer::isPost());
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $get
     */
    public static function fromArrays(array $post, array $get, bool $isPost): self
    {
        $params = [];
        $method = null;

        $param_array = $isPost ? $post : $get;
        foreach ($param_array as $name => $value) {
            if ($name === 'format') {
                continue;
            }
            if ($name === 'method') {
                $method = $value;
            } else {
                $params[(string) $name] = $value;
            }
        }

        if ((! isset($method) || (is_string($method) && ($method === '' || $method === '0'))) && isset($get['method'])) {
            $method = $get['method'];
        }

        if (! is_string($method) || $method === '' || $method === '0') {
            $method = null;
        }

        return new self($method, $params);
    }
}
