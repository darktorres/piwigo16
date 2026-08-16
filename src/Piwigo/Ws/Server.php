<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Closure;
use Piwigo\Admin\Upload\UnsupportedMediaTypeException;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\Core\GetMethodDetailsHandler;
use Piwigo\Ws\Core\GetMethodListHandler;
use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\Event\SendResponse;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Event\WsInvokeAllowed;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The WS framework's own generic method registry/dispatcher -- every
 * `mixed` in this class is genuinely by-design, not unnarrowed: `$methods`
 * holds arbitrarily-shaped per-method registrations (each WS method defines
 * its own param `signature`/`options`), `sendResponse()`/`invoke()` handle
 * an arbitrary WS method's arbitrary return value, and
 * `makeArrayParam()`/`checkType()` coerce an arbitrary WS param's
 * arbitrary value by reference (the param's real type varies per WS
 * method's own registration, same rationale as PluginConfig\EventDispatcher's
 * own pub-sub methods).
 */
final class Server
{
    public ?RequestHandler $requestHandler = null;

    public ?string $requestFormat = null;

    public ?ResponseEncoder $responseEncoder = null;

    public ?string $responseFormat = null;

    /**
     * @var array<string, array{callback: string|array<int, string>|Closure|null, handlerClass: class-string<WsAction>|null, description: string, signature: array<string, array<string, mixed>>, options: array<string, mixed>}>
     */
    private array $methods = [];

    public function __construct(
        private readonly EventDispatcher $eventDispatcher,
        private readonly AccessControl $accessControl,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly CurrentConfig $currentConfig,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * $requestHandler is only read after run()'s own null-check, but
     * intervening method calls (register(), trigger_notify()) mean
     * PHPStan can't carry that narrowing to handleRequest()'s call site.
     */
    private function requestHandler(): RequestHandler
    {
        assert($this->requestHandler instanceof RequestHandler);
        return $this->requestHandler;
    }

    /**
     * sendResponse() is only ever called once setEncoder() has run
     * (the real app-level contract every real caller follows).
     */
    private function responseEncoder(): ResponseEncoder
    {
        assert($this->responseEncoder instanceof ResponseEncoder);
        return $this->responseEncoder;
    }

    /**
     *  Initializes the request handler.
     */
    public function setHandler(string $requestFormat, ?RequestHandler $requestHandler): void
    {
        $this->requestHandler = $requestHandler;
        $this->requestFormat = $requestFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setEncoder(string $responseFormat, ?ResponseEncoder $encoder): void
    {
        $this->responseEncoder = $encoder;
        $this->responseFormat = $responseFormat;
    }

    /**
     * Runs the web service call (handler and response encoder should have been
     * created)
     */
    public function run(): ResponseInterface
    {
        if (! $this->responseEncoder instanceof ResponseEncoder) {
            return ResponseFactory::text(
                'Cannot process your request. Unknown response format.'
                . "\nRequest format: " . ($this->requestFormat ?? '') . ' Response format: ' . ($this->responseFormat ?? '') . "\n",
                400
            );
        }

        if (! $this->requestHandler instanceof RequestHandler) {
            return $this->sendResponse(new WsErrorResponse(400, 'Unknown request format'));
        }

        // add reflection methods
        $this->register(MethodDefinition::forHandler(
            name: 'reflection.getMethodList',
            handlerClass: GetMethodListHandler::class,
        ));
        $this->register(MethodDefinition::forHandler(
            name: 'reflection.getMethodDetails',
            handlerClass: GetMethodDetailsHandler::class,
            params: [
                ParamDefinition::required('methodName'),
            ],
        ));

        $this->eventDispatcher->dispatch(new WsAddMethods($this));
        return $this->requestHandler()
            ->handleRequest($this);
    }

    /**
     * Encodes a response and sends it back to the browser.
     */
    public function sendResponse(mixed $response): ResponseInterface
    {
        $encodedResponse = $this->responseEncoder()
            ->encodeResponse($response);
        $contentType = $this->responseEncoder()
            ->getContentType();

        $this->eventDispatcher->dispatch(new SendResponse($encodedResponse));

        $status = 200;
        if ($response instanceof WsErrorResponse and $response->code() >= 400 and $response->code() < 600) {
            $status = $response->code();
        }

        return ResponseFactory::raw($encodedResponse, [
            'Content-Type' => $contentType . '; charset=utf-8',
        ], $status);
    }

    /**
     * Registers a WS method from a typed {@see MethodDefinition}, built
     * via either `MethodDefinition::forHandler()` (a real DI-resolved
     * `WsAction`) or `MethodDefinition::forLegacyCallback()` (a plain
     * callable -- function name, [class, method], or a first-class
     * callable Closure). Normalizes $def's typed `ParamDefinition` list
     * into the same internal $methods signature shape `invoke()`'s
     * generic validation loop expects.
     */
    public function register(MethodDefinition $def): void
    {
        $signature = [];
        foreach ($def->params as $param) {
            $data = [
                'flags' => $param->flags,
                'type' => $param->type,
            ];
            if ($param->hasDefault) {
                $data['default'] = $param->default;
            }
            if ($param->maxValue !== null) {
                $data['maxValue'] = $param->maxValue;
            }
            if ($param->info !== '') {
                $data['info'] = $param->info;
            }
            $signature[$param->name] = $data;
        }

        $options = [];
        if ($def->requiresAuth) {
            $options['admin_only'] = true;
        }
        if ($def->postOnly) {
            $options['post_only'] = true;
        }
        if ($def->hidden) {
            $options['hidden'] = true;
        }

        $this->methods[$def->name] = [
            'callback' => $def->callback,
            'handlerClass' => $def->handlerClass,
            'description' => $def->description,
            'signature' => $signature,
            'options' => $options,
        ];
    }

    /**
     * The `hidden`-option filter `reflection.getMethodList` (now
     * `Ws\Core\GetMethodListHandler`) exposes -- matches the predicate
     * this class used inline before that method existed as a real
     * `WsAction`.
     *
     * @return list<string>
     */
    public function listVisibleMethodNames(): array
    {
        $methods = array_filter(
            $this->methods,
            static fn (array $m): bool => in_array($m['options']['hidden'] ?? null, [null, false, 0, '0', '', []], true)
        );

        return array_keys($methods);
    }

    public function hasMethod(string $methodName): bool
    {
        return isset($this->methods[$methodName]);
    }

    public function getMethodDescription(string $methodName): string
    {
        return $this->methods[$methodName]['description'] ?? '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMethodSignature(string $methodName): array
    {
        return $this->methods[$methodName]['signature'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMethodOptions(string $methodName): array
    {
        return $this->methods[$methodName]['options'] ?? [];
    }

    /**
     * A minimal, single-fact by-design reader (the current
     * request's HTTP method), same shape as
     * Piwigo\Http\RequestFactory::fromGlobals()'s own sole legitimate
     * superglobal read -- reused by 2 real call sites
     * (RestRequestHandler::handleRequest()'s param-source choice,
     * invoke()'s own post_only method gate), not a bag of request data
     * that would benefit from a {Module}/Request/{Name} DTO wrapper.
     */
    public static function isPost(): bool
    {
        return $_POST !== [];
    }

    public static function makeArrayParam(mixed &$param): void
    {
        if ($param === null) {
            $param = [];
        } else {
            if (! is_array($param)) {
                $param = [$param];
            }
        }
    }

    public static function checkType(mixed &$param, int $type, string $name): ?WsErrorResponse
    {
        // pre-seed the 'options' sub-array so the nested writes below
        // ($opts['options']['min_range'] = ...) target a known array type
        // instead of auto-vivifying through an untyped offset; functionally
        // identical to [] as far as filter_var() is concerned (an empty
        // 'options' map disables the min_range check, same as no options
        // array at all).
        $opts = [
            'options' => [],
        ];
        $msg = '';
        if (self::hasFlag($type, WsParamType::POSITIVE | WsParamType::NOTNULL)) {
            $opts['options']['min_range'] = 1;
            $msg = ' positive and not null';
        } elseif (self::hasFlag($type, WsParamType::POSITIVE)) {
            $opts['options']['min_range'] = 0;
            $msg = ' positive';
        }

        if (is_array($param)) {
            if (self::hasFlag($type, WsParamType::BOOL)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                        return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must only contain booleans');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WsParamType::INT)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_INT, $opts)) === false) {
                        return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must only contain' . $msg . ' integers');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WsParamType::FLOAT)) {
                foreach ($param as &$value) {
                    if (
                        ($value = filter_var($value, FILTER_VALIDATE_FLOAT)) === false
                        or (isset($opts['options']['min_range']) and $value < $opts['options']['min_range'])
                    ) {
                        return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must only contain' . $msg . ' floats');
                    }
                }
                unset($value);
            }
        } elseif ($param !== '') {
            if (self::hasFlag($type, WsParamType::BOOL)) {
                if (($param = filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                    return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must be a boolean');
                }
            } elseif (self::hasFlag($type, WsParamType::INT)) {
                if (($param = filter_var($param, FILTER_VALIDATE_INT, $opts)) === false) {
                    return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must be an' . $msg . ' integer');
                }
            } elseif (self::hasFlag($type, WsParamType::FLOAT)) {
                if (
                    ($param = filter_var($param, FILTER_VALIDATE_FLOAT)) === false
                    or (isset($opts['options']['min_range']) and $param < $opts['options']['min_range'])
                ) {
                    return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must be a' . $msg . ' float');
                }
            }
        }

        return null;
    }

    public static function hasFlag(int $val, int $flag): bool
    {
        return ($val & $flag) === $flag;
    }

    /**
     *  Invokes a registered method. Returns the return of the method (or
     *  a WsErrorResponse object if the method is not found)
     *  @param string $methodName the name of the method to invoke
     *  @param array<string, mixed> $params array of parameters to pass to the invoked method
     */
    public function invoke(string $methodName, array $params): mixed
    {
        if (! isset($this->methods[$methodName])) {
            return new WsErrorResponse(WsError::InvalidMethod->value, 'Method name is not valid');
        }

        $method = $this->methods[$methodName];

        if (isset($method['options']['post_only']) and (bool) $method['options']['post_only'] and ! self::isPost()) {
            return new WsErrorResponse(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and (bool) $method['options']['admin_only'] and ! $this->accessControl->isAdmin()) {
            return new WsErrorResponse(401, 'Access denied');
        }

        if (! $this->isAuthorizedMethodForAPIKEY($methodName)) {
            return new WsErrorResponse(401, 'Access denied');
        }

        // parameter check and data correction
        $signature = $method['signature'];
        $missing_params = [];

        foreach ($signature as $name => $options) {
            // register() always populates 'flags' as an int (WS_PARAM_*
            // constants or 0); the signature is stored back through the
            // loosely-typed $methods property though, so re-assert it here.
            $flags = $options['flags'];
            $flags = is_int($flags) ? $flags : 0;

            // parameter not provided in the request
            if (! array_key_exists($name, $params)) {
                if (! self::hasFlag($flags, WsParamFlag::OPTIONAL)) {
                    $missing_params[] = $name;
                } elseif (array_key_exists('default', $options)) {
                    $params[$name] = $options['default'];
                    if (self::hasFlag($flags, WsParamFlag::FORCE_ARRAY)) {
                        self::makeArrayParam($params[$name]);
                    }
                }
            }
            // parameter provided but empty
            elseif ($params[$name] === '' and ! self::hasFlag($flags, WsParamFlag::OPTIONAL)) {
                $missing_params[] = $name;
            }
            // parameter provided - do some basic checks
            else {
                $the_param = $params[$name];

                if (is_array($the_param) and ! self::hasFlag($flags, WsParamFlag::ACCEPT_ARRAY)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, $name . ' must be scalar');
                }

                if (self::hasFlag($flags, WsParamFlag::FORCE_ARRAY)) {
                    self::makeArrayParam($the_param);
                }

                // same reasoning as $flags above: register() always
                // populates 'type' as an int (WS_TYPE_* constants or 0).
                $type = $options['type'];
                $type = is_int($type) ? $type : 0;
                if ($type > 0) {
                    if (($ret = self::checkType($the_param, $type, $name)) instanceof WsErrorResponse) {
                        return $ret;
                    }
                }

                if (isset($options['maxValue']) and $the_param > $options['maxValue']) {
                    $the_param = $options['maxValue'];
                }

                $params[$name] = $the_param;
            }
        }

        if ((bool) count($missing_params)) {
            return new WsErrorResponse(WsError::MissingParam->value, 'Missing parameters: ' . implode(',', $missing_params));
        }

        $result = $this->eventDispatcher->dispatch(new WsInvokeAllowed(true, $methodName, $params))
            ->value;

        $is_error = false;
        if ($result instanceof WsErrorResponse) {
            $is_error = true;
        }

        if (! $is_error) {
            $handlerClass = $method['handlerClass'];
            if ($handlerClass !== null) {
                $handler = $this->container->get($handlerClass);
                assert($handler instanceof WsAction);
                try {
                    $result = $handler($params);
                } catch (WsParamException $e) {
                    return new WsErrorResponse(403, $e->getMessage());
                } catch (UnsupportedMediaTypeException $e) {
                    return new WsErrorResponse(415, $e->getMessage());
                }
                if ($result instanceof WsResult) {
                    $result = $result->toArray();
                }
            } else {
                // MethodDefinition::forLegacyCallback()'s own type already
                // constrains $def->callback to a genuinely callable function
                // name, [class, method] pair, or Closure
                assert(is_callable($method['callback']));
                $result = call_user_func_array($method['callback'], [$params, &$this]);
            }
        }

        return $result;
    }

    /**
     * Checks the forbidden-methods list against $methodName itself, not
     * against the original HTTP request's method -- this keeps the check
     * correct for recursive invoke() calls (e.g. Permissions::add()/
     * remove() calling $service->invoke('pwg.permissions.getList', ...)
     * after their own mutation).
     */
    public function isAuthorizedMethodForAPIKEY(string $methodName): bool
    {

        // if the request is made with an API key (via header or session API key),
        // we check whether the requested method is on the
        // list of prohibited methods ($this->currentConfig->apiKeyForbiddenMethods) for API keys
        // if it is, access is refused (false)
        if (
            $this->apiKeyRequestFlag->isActive()
            or new ConnectedWithSession()
                ->get() === ConnectedWith::WsSessionLoginApiKey
        ) {
            $forbidden_methods = $this->currentConfig->apiKeyForbiddenMethods;

            if (in_array($methodName, array_map(strval(...), array_filter($forbidden_methods, is_scalar(...))), true)) {
                return false;
            }
        }

        return true;
    }
}
