<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/* WEB SERVICE CORE CLASSES************************************************
 * PwgServer - main object - the link between web service methods, request
 *  handler and response encoder
 * PwgRequestHandler - base class for handlers
 * PwgResponseEncoder - base class for response encoders
 * PwgError, PwgNamedArray, PwgNamedStruct - can be used by web service functions
 * as return values
 */

define('WS_PARAM_ACCEPT_ARRAY', 0x010000);
define('WS_PARAM_FORCE_ARRAY', 0x030000);
define('WS_PARAM_OPTIONAL', 0x040000);

define('WS_TYPE_BOOL', 0x01);
define('WS_TYPE_INT', 0x02);
define('WS_TYPE_FLOAT', 0x04);
define('WS_TYPE_POSITIVE', 0x10);
define('WS_TYPE_NOTNULL', 0x20);
define('WS_TYPE_ID', WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);

define('WS_ERR_INVALID_METHOD', 501);
define('WS_ERR_MISSING_PARAM', 1002);
define('WS_ERR_INVALID_PARAM', 1003);

define('WS_XML_ATTRIBUTES', 'attributes_xml_');

/**
 * PwgError object can be returned from any web service function implementation.
 */
class PwgError
{
    private int $_code;

    private string $_codeText;

    public function __construct(
        int $code,
        string $codeText
    ) {
        if ($code >= 400 and $code < 600) {
            set_status_header($code, $codeText);
        }

        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code(): int
    {
        return $this->_code;
    }

    public function message(): string
    {
        return $this->_codeText;
    }
}

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
class PwgNamedArray
{
    /* private */
    /**
     * @var array<string, int>
     */
    public $_xmlAttributes;

    /**
     * Constructs a named array
     * @param array<int, mixed> $_content (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param string[] $xmlAttributes of sub-item attributes that will be encoded as
     *      xml attributes instead of xml child elements
     */
    public function __construct(
        public array $_content,
        public string $_itemName,
        array $xmlAttributes = []
    ) {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
/**
 * Simple wrapper around a "struct" (php array whose keys are not consecutive
 * integers starting at 0). Provides naming clues for xml output (what is xml
 * attributes and what is element)
 */
class PwgNamedStruct
{
    /* private */
    /**
     * @var array<string, int>
     */
    public $_xmlAttributes;

    /**
     * Constructs a named struct (usually returned by web service function
     * implementation)
     * @param array<string, mixed> $_content the actual content (php array)
     * @param string[]|null $xmlAttributes name of the keys in $content that will be
     *    encoded as xml attributes (if null - automatically prefer xml attributes
     *    whenever possible)
     * @param string[]|null $xmlElements keys in $content to always treat as xml elements
     */
    public function __construct(
        public array $_content,
        ?array $xmlAttributes = null,
        ?array $xmlElements = null
    ) {
        if (isset($xmlAttributes)) {
            $this->_xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->_xmlAttributes = [];
            foreach ($this->_content as $key => $value) {
                if (! empty($key) and (is_scalar($value) or $value === null)) {
                    if (empty($xmlElements) or ! in_array($key, $xmlElements)) {
                        $this->_xmlAttributes[$key] = 1;
                    }
                }
            }
        }
    }
}

/**
 * Abstract base class for request handlers.
 */
abstract class PwgRequestHandler
{
    /** Virtual abstract method. Decodes the request (GET or POST) handles the
     * method invocation as well as response sending.
     */
    abstract public function handleRequest(PwgServer &$service): void;
}

/**
 * Base class for web service response encoder.
 */
abstract class PwgResponseEncoder
{
    /** encodes the web service response to the appropriate output format
     * @param mixed $response the unencoded result of a service method call
     */
    abstract public function encodeResponse($response): string|false;

    /** default "Content-Type" http header for this kind of response format
     */
    abstract public function getContentType(): string;

    /**
     * returns true if the parameter is a 'struct' (php array type whose keys are
     * NOT consecutive integers starting with 0)
     */
    public static function is_struct(mixed &$data): bool
    {
        if (is_array($data)) {
            if (range(0, count($data) - 1) !== array_keys($data)) { # string keys, unordered, non-incremental keys, .. - whatever, make object
                return true;
            }
        }
        return false;
    }

    /**
     * removes all XML formatting from $response (named array, named structs, etc)
     * usually called by every response encoder, except rest xml.
     */
    public static function flattenResponse(mixed &$value): void
    {
        self::flatten($value);
    }

    private static function flatten(mixed &$value): void
    {
        if (is_object($value)) {
            $class = strtolower(@$value::class);
            if ($class == 'pwgnamedarray') {
                $value = $value->_content;
            }
            if ($class == 'pwgnamedstruct') {
                $value = $value->_content;
            }
        }

        if (! is_array($value)) {
            return;
        }

        if (self::is_struct($value)) {
            if (isset($value[WS_XML_ATTRIBUTES])) {
                $value = array_merge($value, $value[WS_XML_ATTRIBUTES]);
                unset($value[WS_XML_ATTRIBUTES]);
            }
        }

        foreach ($value as $key => &$v) {
            self::flatten($v);
        }
    }
}

class PwgServer
{
    public ?PwgRequestHandler $_requestHandler = null;

    public ?string $_requestFormat = null;

    public ?PwgResponseEncoder $_responseEncoder = null;

    public ?string $_responseFormat = null;

    /**
     * @var array<string, array{callback: callable, description: string, signature: array<string, array<string, mixed>>, include: string, options: array<string, mixed>}>
     */
    public $_methods = [];

    public function __construct() {}

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
        if ($this->_responseEncoder === null) {
            set_status_header(400);
            @header('Content-Type: text/plain');
            echo 'Cannot process your request. Unknown response format.
Request format: ' . @$this->_requestFormat . ' Response format: ' . @$this->_responseFormat . "\n";
            var_export($this);
            die(0);
        }

        if ($this->_requestHandler === null) {
            $this->sendResponse(new PwgError(400, 'Unknown request format'));
            return;
        }

        // add reflection methods
        $this->addMethod(
            'reflection.getMethodList',
            ['PwgServer', 'ws_getMethodList']
        );
        $this->addMethod(
            'reflection.getMethodDetails',
            ['PwgServer', 'ws_getMethodDetails'],
            ['methodName']
        );

        trigger_notify('ws_add_methods', [&$this]);
        uksort($this->_methods, strnatcmp(...));
        $this->_requestHandler->handleRequest($this);
    }

    /**
     * Encodes a response and sends it back to the browser.
     */
    public function sendResponse(mixed $response): void
    {
        $encodedResponse = $this->_responseEncoder->encodeResponse($response);
        $contentType = $this->_responseEncoder->getContentType();

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
            $params = array_flip($params);
        }

        foreach ($params as $param => $data) {
            if (! is_array($data)) {
                $params[$param] = [
                    'flags' => 0,
                    'type' => 0,
                ];
            } else {
                if (! isset($data['flags'])) {
                    $data['flags'] = 0;
                }
                if (array_key_exists('default', $data)) {
                    $data['flags'] |= WS_PARAM_OPTIONAL;
                }
                if (! isset($data['type'])) {
                    $data['type'] = 0;
                }
                $params[$param] = $data;
            }
        }

        $this->_methods[$methodName] = [
            'callback' => $callback,
            'description' => $description,
            'signature' => $params,
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

    public static function checkType(mixed &$param, int $type, string $name): ?\PwgError
    {
        $opts = [];
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

        if (isset($method['options']['post_only']) and $method['options']['post_only'] and ! self::isPost()) {
            return new PwgError(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and $method['options']['admin_only'] and ! is_admin()) {
            return new PwgError(401, 'Access denied');
        }

        if (! $this->isAuthorizedMethodForAPIKEY()) {
            return new PwgError(401, 'Access denied');
        }

        // parameter check and data correction
        $signature = $method['signature'];
        $missing_params = [];

        foreach ($signature as $name => $options) {
            $flags = $options['flags'];

            // parameter not provided in the request
            if (! array_key_exists((string) $name, $params)) {
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

                if ($options['type'] > 0) {
                    if (($ret = self::checkType($the_param, $options['type'], $name)) instanceof \PwgError) {
                        return $ret;
                    }
                }

                if (isset($options['maxValue']) and $the_param > $options['maxValue']) {
                    $the_param = $options['maxValue'];
                }

                $params[$name] = $the_param;
            }
        }

        if (count($missing_params)) {
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
     * @return \PwgError|array<string, mixed>
     */
    public static function ws_getMethodDetails(array $params, self &$service): \PwgError|array
    {
        $methodName = $params['methodName'];

        if (! $service->hasMethod($methodName)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Requested method does not exist');
        }

        $res = [
            'name' => $methodName,
            'description' => $service->getMethodDescription($methodName),
            'params' => [],
            'options' => $service->getMethodOptions($methodName),
        ];

        foreach ($service->getMethodSignature($methodName) as $name => $options) {
            $param_data = [
                'name' => $name,
                'optional' => self::hasFlag($options['flags'], WS_PARAM_OPTIONAL),
                'acceptArray' => self::hasFlag($options['flags'], WS_PARAM_ACCEPT_ARRAY),
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

            if (self::hasFlag($options['type'], WS_TYPE_BOOL)) {
                $param_data['type'] = 'bool';
            } elseif (self::hasFlag($options['type'], WS_TYPE_INT)) {
                $param_data['type'] = 'int';
            } elseif (self::hasFlag($options['type'], WS_TYPE_FLOAT)) {
                $param_data['type'] = 'float';
            }
            if (self::hasFlag($options['type'], WS_TYPE_POSITIVE)) {
                $param_data['type'] .= ' positive';
            }
            if (self::hasFlag($options['type'], WS_TYPE_NOTNULL)) {
                $param_data['type'] .= ' notnull';
            }

            $res['params'][] = $param_data;
        }
        return $res;
    }

    public function isAuthorizedMethodForAPIKEY(): bool
    {
        global $conf;

        // if the request is made with an API key (via header or session API key),
        // we check whether the requested method is on the
        // list of prohibited methods ($conf['api_key_forbidden_methods']) for API keys
        // if it is, access is refused (false)
        if (
            defined('PWG_API_KEY_REQUEST')
            or (isset($_SESSION['connected_with']) and $_SESSION['connected_with'] === 'ws_session_login_api_key')
        ) {
            if (in_array($_REQUEST['method'], $conf['api_key_forbidden_methods'])) {
                return false;
            }
        }

        return true;
    }
}
