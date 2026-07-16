<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Ws\Encoder\PwgResponseEncoder;

class PwgServer
{
    public ?PwgRequestHandler $_requestHandler = null;

    public ?string $_requestFormat = null;

    public ?PwgResponseEncoder $_responseEncoder = null;

    public ?string $_responseFormat = null;

    /**
     * @var array<string, array{callback: string|array<int, string>, description: string, signature: array<string, array<string, mixed>>, include: string, options: array<string, mixed>}>
     */
    public $_methods = [];

    public function __construct() {}

    /**
     * $_requestHandler is only read after run()'s own null-check, but
     * intervening method calls (addMethod(), trigger_notify()) mean
     * PHPStan can't carry that narrowing to handleRequest()'s call site.
     */
    private function requestHandler(): PwgRequestHandler
    {
        assert($this->_requestHandler instanceof PwgRequestHandler);
        return $this->_requestHandler;
    }

    /**
     * sendResponse() is only ever called once setEncoder() has run
     * (the real app-level contract every real caller follows).
     */
    private function responseEncoder(): PwgResponseEncoder
    {
        assert($this->_responseEncoder instanceof PwgResponseEncoder);
        return $this->_responseEncoder;
    }

    /**
     *  Initializes the request handler.
     */
    public function setHandler(string $requestFormat, ?PwgRequestHandler &$requestHandler): void
    {
        $this->_requestHandler = &$requestHandler;
        $this->_requestFormat = $requestFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setEncoder(string $responseFormat, ?PwgResponseEncoder &$encoder): void
    {
        $this->_responseEncoder = &$encoder;
        $this->_responseFormat = $responseFormat;
    }

    /**
     * Runs the web service call (handler and response encoder should have been
     * created)
     */
    public function run(): void
    {
        if (! $this->_responseEncoder instanceof PwgResponseEncoder) {
            set_status_header(400);
            @header('Content-Type: text/plain');
            echo 'Cannot process your request. Unknown response format.
Request format: ' . @$this->_requestFormat . ' Response format: ' . @$this->_responseFormat . "\n";
            var_export($this);
            die(0);
        }

        if (! $this->_requestHandler instanceof PwgRequestHandler) {
            $this->sendResponse(new PwgError(400, 'Unknown request format'));
            return;
        }

        // add reflection methods
        $this->addMethod(
            'reflection.getMethodList',
            [self::class, 'ws_getMethodList']
        );
        $this->addMethod(
            'reflection.getMethodDetails',
            [self::class, 'ws_getMethodDetails'],
            ['methodName']
        );

        trigger_notify('ws_add_methods', [&$this]);
        uksort($this->_methods, strnatcmp(...));
        $this->requestHandler()
            ->handleRequest($this);
    }

    /**
     * Encodes a response and sends it back to the browser.
     */
    public function sendResponse(mixed $response): void
    {
        $encodedResponse = $this->responseEncoder()
            ->encodeResponse($response);
        $contentType = $this->responseEncoder()
            ->getContentType();

        @header('Content-Type: ' . $contentType . '; charset=' . get_pwg_charset());
        print_r($encodedResponse);
        trigger_notify('sendResponse', $encodedResponse);
    }

    /**
     * Registers a web service method.
     * @param string $methodName - the name of the method as seen externally
     * @param string|array<int, string> $callback - a callable (function name,
     *   or [class, method]); typed as string|array rather than native
     *   `callable`, since most registrations in ws.php name a function that
     *   is not yet defined at this point (its file is only include_once'd
     *   lazily, from $include_file, right before invoke() actually calls it)
     *   — a native `callable` type would fail PHP's is-it-currently-callable
     *   check immediately at registration time
     * @param array<int, string>|array<string, mixed>|null $params - either a
     *   plain list of allowed parameter names (shorthand, no options) or a map
     *   of allowed parameter names to their options; many real registrations
     *   in ws.php (e.g. pwg.getVersion, pwg.getInfos, pwg.session.getStatus)
     *   explicitly pass null for "no params"
     *    @option mixed default (optional)
     *    @option int flags (optional)
     *      possible values: WS_PARAM_ALLOW_ARRAY, WS_PARAM_FORCE_ARRAY, WS_PARAM_OPTIONAL
     *    @option int type (optional)
     *      possible values: WS_TYPE_BOOL, WS_TYPE_INT, WS_TYPE_FLOAT, WS_TYPE_ID
     *                       WS_TYPE_POSITIVE, WS_TYPE_NOTNULL
     *    @option int|float maxValue (optional)
     * @param string|null $description - a description of the method; some
     *   real registrations in ws.php explicitly pass null for "no description"
     * @param string $include_file - a file to be included befaore the callback is executed
     * @param array<string, mixed> $options
     *    @option bool hidden (optional) - if true, this method won't be visible by reflection.getMethodList
     *    @option bool admin_only (optional)
     *    @option bool post_only (optional)
     */
    public function addMethod(string $methodName, string|array $callback, ?array $params = [], ?string $description = '', string $include_file = '', array $options = []): void
    {
        if (! is_array($params)) {
            $params = [];
        }

        // ws.php's own registrations (e.g. pwg.plugins.performAction,
        // pwg.themes.performAction) explicitly pass null for "no description"
        if ($description === null) {
            $description = '';
        }

        if (range(0, count($params) - 1) === array_keys($params)) {
            // shorthand form: a plain list of allowed parameter names
            // (strings); array_filter() proves that to PHPStan since the
            // declared type also allows the array<string, mixed> options-map
            // form, whose values aren't guaranteed int|string.
            $params = array_flip(array_filter($params, is_string(...)));
        }

        $signature = [];
        foreach ($params as $param => $data) {
            $param = (string) $param;
            if (! is_array($data)) {
                $signature[$param] = [
                    'flags' => 0,
                    'type' => 0,
                ];
            } else {
                /** @var array<string, mixed> $data */
                // every real registration in ws.php that sets 'flags' uses
                // one of the WS_PARAM_* int constants; fall back to 0 for
                // anything else (missing, or a non-int value).
                if (! isset($data['flags']) or ! is_int($data['flags'])) {
                    $data['flags'] = 0;
                }
                if (array_key_exists('default', $data)) {
                    $data['flags'] |= WS_PARAM_OPTIONAL;
                }
                if (! isset($data['type'])) {
                    $data['type'] = 0;
                }
                $signature[$param] = $data;
            }
        }

        $this->_methods[$methodName] = [
            'callback' => $callback,
            'description' => $description,
            'signature' => $signature,
            'include' => $include_file,
            'options' => $options,
        ];
    }

    public function hasMethod(string $methodName): bool
    {
        return isset($this->_methods[$methodName]);
    }

    public function getMethodDescription(string $methodName): string
    {
        return $this->_methods[$methodName]['description'] ?? '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMethodSignature(string $methodName): array
    {
        return $this->_methods[$methodName]['signature'] ?? [];
    }

    /**
     * @since 2.6
     *
     * @return array<string, mixed>
     */
    public function getMethodOptions(string $methodName): array
    {
        return $this->_methods[$methodName]['options'] ?? [];
    }

    public static function isPost(): bool
    {
        return ! empty($_POST);
    }

    public static function makeArrayParam(mixed &$param): void
    {
        if ($param == null) {
            $param = [];
        } else {
            if (! is_array($param)) {
                $param = [$param];
            }
        }
    }

    public static function checkType(mixed &$param, int $type, string $name): ?PwgError
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
        if (self::hasFlag($type, WS_TYPE_POSITIVE | WS_TYPE_NOTNULL)) {
            $opts['options']['min_range'] = 1;
            $msg = ' positive and not null';
        } elseif (self::hasFlag($type, WS_TYPE_POSITIVE)) {
            $opts['options']['min_range'] = 0;
            $msg = ' positive';
        }

        if (is_array($param)) {
            if (self::hasFlag($type, WS_TYPE_BOOL)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must only contain booleans');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_INT, $opts)) === false) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must only contain' . $msg . ' integers');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                foreach ($param as &$value) {
                    if (
                        ($value = filter_var($value, FILTER_VALIDATE_FLOAT)) === false
                        or (isset($opts['options']['min_range']) and $value < $opts['options']['min_range'])
                    ) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must only contain' . $msg . ' floats');
                    }
                }
                unset($value);
            }
        } elseif ($param !== '') {
            if (self::hasFlag($type, WS_TYPE_BOOL)) {
                if (($param = filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must be a boolean');
                }
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                if (($param = filter_var($param, FILTER_VALIDATE_INT, $opts)) === false) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must be an' . $msg . ' integer');
                }
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                if (
                    ($param = filter_var($param, FILTER_VALIDATE_FLOAT)) === false
                    or (isset($opts['options']['min_range']) and $param < $opts['options']['min_range'])
                ) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must be a' . $msg . ' float');
                }
            }
        }

        return null;
    }

    public static function hasFlag(int $val, int $flag): bool
    {
        return ($val & $flag) == $flag;
    }

    /**
     *  Invokes a registered method. Returns the return of the method (or
     *  a PwgError object if the method is not found)
     *  @param string $methodName the name of the method to invoke
     *  @param array<string, mixed> $params array of parameters to pass to the invoked method
     */
    public function invoke(string $methodName, array $params): mixed
    {
        $method = @$this->_methods[$methodName];

        if ($method == null) {
            return new PwgError(WS_ERR_INVALID_METHOD, 'Method name is not valid');
        }

        if (isset($method['options']['post_only']) and (bool) $method['options']['post_only'] and ! self::isPost()) {
            return new PwgError(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and (bool) $method['options']['admin_only'] and ! \Piwigo\Auth\AccessControl::isAdmin()) {
            return new PwgError(401, 'Access denied');
        }

        if (! $this->isAuthorizedMethodForAPIKEY()) {
            return new PwgError(401, 'Access denied');
        }

        // parameter check and data correction
        $signature = $method['signature'];
        $missing_params = [];

        foreach ($signature as $name => $options) {
            // addMethod() always populates 'flags' as an int (WS_PARAM_*
            // constants or 0); the signature is stored back through the
            // loosely-typed $_methods property though, so re-assert it here.
            $flags = $options['flags'];
            $flags = is_int($flags) ? $flags : 0;

            // parameter not provided in the request
            if (! array_key_exists($name, $params)) {
                if (! self::hasFlag($flags, WS_PARAM_OPTIONAL)) {
                    $missing_params[] = $name;
                } elseif (array_key_exists('default', $options)) {
                    $params[$name] = $options['default'];
                    if (self::hasFlag($flags, WS_PARAM_FORCE_ARRAY)) {
                        self::makeArrayParam($params[$name]);
                    }
                }
            }
            // parameter provided but empty
            elseif ($params[$name] === '' and ! self::hasFlag($flags, WS_PARAM_OPTIONAL)) {
                $missing_params[] = $name;
            }
            // parameter provided - do some basic checks
            else {
                $the_param = $params[$name];

                if (is_array($the_param) and ! self::hasFlag($flags, WS_PARAM_ACCEPT_ARRAY)) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name . ' must be scalar');
                }

                if (self::hasFlag($flags, WS_PARAM_FORCE_ARRAY)) {
                    self::makeArrayParam($the_param);
                }

                // same reasoning as $flags above: addMethod() always
                // populates 'type' as an int (WS_TYPE_* constants or 0).
                $type = $options['type'];
                $type = is_int($type) ? $type : 0;
                if ($type > 0) {
                    if (($ret = self::checkType($the_param, $type, $name)) instanceof PwgError) {
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
            return new PwgError(WS_ERR_MISSING_PARAM, 'Missing parameters: ' . implode(',', $missing_params));
        }

        $result = trigger_change('ws_invoke_allowed', true, $methodName, $params);

        $is_error = false;
        if ($result instanceof PwgError) {
            $is_error = true;
        }

        if (! $is_error) {
            if (! empty($method['include'])) {
                include_once $method['include'];
            }
            // every real registration (ws.php, and this class's own
            // reflection methods) passes a genuinely callable function
            // name or [class, method] pair
            assert(is_callable($method['callback']));
            $result = call_user_func_array($method['callback'], [$params, &$this]);
        }

        return $result;
    }

    /**
     * WS reflection method implementation: lists all available methods
     *
     * @param array<string, mixed> $params
     * @return array{methods: PwgNamedArray}
     */
    public static function ws_getMethodList(array $params, self &$service): array
    {
        $methods = array_filter(
            $service->_methods,
            fn (array $m): bool => empty($m['options']['hidden'])
        );
        return [
            'methods' => new PwgNamedArray(array_keys($methods), 'method'),
        ];
    }

    /**
     * WS reflection method implementation: gets information about a given method
     *
     * @param array<string, mixed> $params
     * @return PwgError|array<string, mixed>
     */
    public static function ws_getMethodDetails(array $params, self &$service): PwgError|array
    {
        $methodName = $params['methodName'];

        // 'methodName' is registered with no WS_TYPE_* flag, so invoke()
        // never coerces it; narrow it here instead of trusting the raw
        // request value.
        if (! is_string($methodName) or ! $service->hasMethod($methodName)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Requested method does not exist');
        }

        $res = [
            'name' => $methodName,
            'description' => $service->getMethodDescription($methodName),
            'params' => [],
            'options' => $service->getMethodOptions($methodName),
        ];

        foreach ($service->getMethodSignature($methodName) as $name => $options) {
            // same reasoning as invoke(): addMethod() always populates
            // 'flags'/'type' as ints, but the signature travels back out
            // through the loosely-typed $_methods property.
            $flags = $options['flags'];
            $flags = is_int($flags) ? $flags : 0;
            $type = $options['type'];
            $type = is_int($type) ? $type : 0;

            $param_data = [
                'name' => $name,
                'optional' => self::hasFlag($flags, WS_PARAM_OPTIONAL),
                'acceptArray' => self::hasFlag($flags, WS_PARAM_ACCEPT_ARRAY),
                'type' => 'mixed',
            ];

            if (isset($options['default'])) {
                $param_data['defaultValue'] = $options['default'];
            }
            if (isset($options['maxValue'])) {
                $param_data['maxValue'] = $options['maxValue'];
            }
            if (isset($options['info'])) {
                $param_data['info'] = $options['info'];
            }

            if (self::hasFlag($type, WS_TYPE_BOOL)) {
                $param_data['type'] = 'bool';
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                $param_data['type'] = 'int';
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                $param_data['type'] = 'float';
            }
            if (self::hasFlag($type, WS_TYPE_POSITIVE)) {
                $param_data['type'] .= ' positive';
            }
            if (self::hasFlag($type, WS_TYPE_NOTNULL)) {
                $param_data['type'] .= ' notnull';
            }

            $res['params'][] = $param_data;
        }
        return $res;
    }

    public function isAuthorizedMethodForAPIKEY(): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // if the request is made with an API key (via header or session API key),
        // we check whether the requested method is on the
        // list of prohibited methods ($conf['api_key_forbidden_methods']) for API keys
        // if it is, access is refused (false)
        if (
            ApiKeyRequestFlag::isActive()
            or (isset($_SESSION['connected_with']) and $_SESSION['connected_with'] === 'ws_session_login_api_key')
        ) {
            $forbidden_methods = $conf['api_key_forbidden_methods'] ?? [];
            if (! is_array($forbidden_methods)) {
                $forbidden_methods = [];
            }

            if (in_array($_REQUEST['method'], $forbidden_methods)) {
                return false;
            }
        }

        return true;
    }
}
