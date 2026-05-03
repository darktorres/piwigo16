<?php

declare(strict_types=1);

global $persistent_cache;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**** WEB SERVICE CORE CLASSES************************************************
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

    public function __construct(int $code, string $codeText)
    {
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
    /*private*/
    /**
     * @var mixed[]
     */
    public $_xmlAttributes;

    /**
     * Constructs a named array
     * @param mixed $_content array (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param array $xmlAttributes sub-item attributes that will be encoded as xml attributes
     */
    /** @param string[] $xmlAttributes */
    public function __construct(public mixed $_content, public string $_itemName, array $xmlAttributes = [])
    {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }

    public function getContent(): mixed
    {
        return $this->_content;
    }
}
/**
 * Simple wrapper around a "struct" (php array whose keys are not consecutive
 * integers starting at 0). Provides naming clues for xml output (what is xml
 * attributes and what is element)
 */
class PwgNamedStruct
{
    /*private*/
    /** @var array<mixed> */
    public array $_xmlAttributes = [];

    /**
     * Constructs a named struct (usually returned by web service function
     * implementation)
     * @param mixed $_content the actual content (php array)
     * @param string[]|null $xmlAttributes name of the keys in $content to encode as xml attributes
     * @param string[]|null $xmlElements name of the keys to encode as xml elements
     */
    public function __construct(public mixed $_content, ?array $xmlAttributes = null, ?array $xmlElements = null)
    {
        if (isset($xmlAttributes)) {
            $this->_xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->_xmlAttributes = [];
            if (is_array($this->_content)) {
                foreach ($this->_content as $key => $value) {
                    if (!empty($key) and (is_scalar($value) or is_null($value))) {
                        if (empty($xmlElements) or !in_array($key, $xmlElements)) {
                            $this->_xmlAttributes[$key] = 1;
                        }
                    }
                }
            }
        }
    }

    public function getContent(): mixed
    {
        return $this->_content;
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
 *
 * Base class for web service response encoder.
 */
abstract class PwgResponseEncoder
{
    /** encodes the web service response to the appropriate output format
     * @param mixed $response the unencoded result of a service method call
     */
    abstract public function encodeResponse(mixed $response): mixed;

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
        if ($value instanceof PwgNamedArray) {
            $value = $value->_content;
        } elseif ($value instanceof PwgNamedStruct) {
            $value = $value->_content;
        }

        if (!is_array($value)) {
            return;
        }

        // $value is array<mixed> here; re-check after is_struct (which takes mixed &$data)
        if (self::is_struct($value)) {
            if (is_array($value) && isset($value[WS_XML_ATTRIBUTES]) && is_array($value[WS_XML_ATTRIBUTES])) {
                $value = array_merge($value, $value[WS_XML_ATTRIBUTES]);
                unset($value[WS_XML_ATTRIBUTES]);
            }
        }

        if (is_array($value)) {
            foreach ($value as $key => &$v) {
                self::flatten($v);
            }
            unset($v);
        }
    }
}



/**
 * @phpstan-type WsParam array{flags: int, type: int, default?: mixed, maxValue?: int|float, info?: string, chooseList?: list<mixed>}
 * @phpstan-type WsMethod array{callback: mixed, description: string, signature: array<string, WsParam>, include: string, options: array<string, mixed>}
 */
class PwgServer
{
    public ?PwgRequestHandler $_requestHandler = null;
    public string $_requestFormat = '';
    public ?PwgResponseEncoder $_responseEncoder = null;
    public string $_responseFormat = '';

    /** @var array<string, WsMethod> */
    private array $_methods = [];

    public function __construct()
    {
    }

    /** @return array<string, WsMethod> */
    public function getMethods(): array
    {
        return $this->_methods;
    }

    /**
     *  Initializes the request handler.
     */
    public function setHandler(string $requestFormat, PwgRequestHandler &$requestHandler): void
    {
        $this->_requestHandler = &$requestHandler;
        $this->_requestFormat = $requestFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setEncoder(string $responseFormat, PwgResponseEncoder &$encoder): void
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
        if (is_null($this->_responseEncoder)) {
            set_status_header(400);
            @header('Content-Type: text/plain');
            echo('Cannot process your request. Unknown response format.
Request format: '.@$this->_requestFormat.' Response format: '.@$this->_responseFormat."\n");
            var_export($this);
            die(0);
        }

        if (is_null($this->_requestHandler)) {
            $this->sendResponse(new \Piwigo\Ws\PwgError(400, 'Unknown request format'));
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

        $requestHandler = $this->_requestHandler;
        if ($requestHandler === null) {
            return;
        }

        trigger_notify('ws_add_methods', [&$this]);
        uksort($this->_methods, strnatcmp(...));
        $requestHandler->handleRequest($this);
    }

    /**
     * Encodes a response and sends it back to the browser.
     */
    public function sendResponse(mixed $response): void
    {
        if ($this->_responseEncoder === null) {
            return;
        }

        $encodedResponse = $this->_responseEncoder->encodeResponse($response);
        $contentType = $this->_responseEncoder->getContentType();

        @header('Content-Type: '.$contentType.'; charset='.get_pwg_charset());
        print_r($encodedResponse);
        trigger_notify('sendResponse', $encodedResponse);
    }

    /**
     * Registers a web service method.
     * @param string $methodName the name of the method as seen externally
     * @param mixed $callback php method to be invoked internally
     * @param array<mixed>|null $params map of allowed parameter names with options
     * @param string|null $description a description of the method
     * @param string $include_file a file to be included before the callback is executed
     * @param array<string, mixed> $options hidden, admin_only, post_only flags
     */
    public function addMethod(string $methodName, mixed $callback, ?array $params = null, ?string $description = null, string $include_file = '', array $options = []): void
    {
        $params ??= [];
        $description ??= '';
        // Normalize sequential list of param names to keyed array
        if (range(0, count($params) - 1) === array_keys($params)) {
            $keyed = [];
            foreach ($params as $name) {
                if (is_string($name)) {
                    $keyed[$name] = ['flags' => 0, 'type' => 0];
                }
            }
            $params = $keyed;
        }

        /** @var array<string, WsParam> $signature */
        $signature = [];
        foreach ($params as $param => $data) {
            $param = (string) $param;
            if (!is_array($data)) {
                $signature[$param] = ['flags' => 0, 'type' => 0];
            } else {
                $flags = isset($data['flags']) && is_int($data['flags']) ? $data['flags'] : 0;
                if (array_key_exists('default', $data)) {
                    $flags |= WS_PARAM_OPTIONAL;
                }
                $type = isset($data['type']) && is_int($data['type']) ? $data['type'] : 0;
                $entry = ['flags' => $flags, 'type' => $type];
                if (array_key_exists('default', $data)) {
                    $entry['default'] = $data['default'];
                }
                if (isset($data['maxValue']) && (is_int($data['maxValue']) || is_float($data['maxValue']))) {
                    $entry['maxValue'] = $data['maxValue'];
                }
                if (isset($data['info']) && is_string($data['info'])) {
                    $entry['info'] = $data['info'];
                }
                $signature[$param] = $entry;
            }
        }

        $this->_methods[$methodName] = [
            'callback'    => $callback,
            'description' => $description,
            'signature'   => $signature,
            'include'     => $include_file,
            'options'     => $options,
        ];
    }

    public function hasMethod(string $methodName): bool
    {
        return isset($this->_methods[$methodName]);
    }

    public function getMethodDescription(string $methodName): string
    {
        return isset($this->_methods[$methodName]) ? $this->_methods[$methodName]['description'] : '';
    }

    /** @return array<string, WsParam> */
    public function getMethodSignature(string $methodName): array
    {
        return isset($this->_methods[$methodName]) ? $this->_methods[$methodName]['signature'] : [];
    }

    /**
     * @since 2.6
     * @return array<string, mixed>
     */
    public function getMethodOptions(string $methodName): array
    {
        return isset($this->_methods[$methodName]) ? $this->_methods[$methodName]['options'] : [];
    }

    public static function isPost(): bool
    {
        $input = file_get_contents('php://input');
        return !empty($_POST) || ($input !== false && strlen($input) > 0);
    }

    public static function makeArrayParam(mixed &$param): void
    {
        if ($param == null) {
            $param = [];
        } else {
            if (!is_array($param)) {
                $param = [$param];
            }
        }
    }

    public static function checkType(mixed &$param, int $type, string $name): ?\Piwigo\Ws\PwgError
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
                        return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain booleans');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_INT, $opts)) === false) {
                        return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain'.$msg.' integers');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                foreach ($param as &$value) {
                    if (
                        ($value = filter_var($value, FILTER_VALIDATE_FLOAT)) === false
                        or (isset($opts['options']['min_range']) and $value < $opts['options']['min_range'])
                    ) {
                        return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain'.$msg.' floats');
                    }
                }
                unset($value);
            }
        } elseif ($param !== '') {
            if (self::hasFlag($type, WS_TYPE_BOOL)) {
                if (($param = filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                    return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must be a boolean');
                }
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                if (($param = filter_var($param, FILTER_VALIDATE_INT, $opts)) === false) {
                    return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must be an'.$msg.' integer');
                }
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                if (
                    ($param = filter_var($param, FILTER_VALIDATE_FLOAT)) === false
                    or (isset($opts['options']['min_range']) and $param < $opts['options']['min_range'])
                ) {
                    return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must be a'.$msg.' float');
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
     * Invokes a registered method. Returns the return of the method (or
     * a PwgError object if the method is not found)
     * @param string $methodName the name of the method to invoke
     * @param array<mixed> $params array of parameters to pass to the invoked method
     * @return mixed
     */
    public function invoke(string $methodName, array $params): mixed
    {
        if (!isset($this->_methods[$methodName])) {
            return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_METHOD, 'Method name is not valid');
        }
        $method = $this->_methods[$methodName];

        if (isset($method['options']['post_only']) and $method['options']['post_only'] and !self::isPost()) {
            return new \Piwigo\Ws\PwgError(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and $method['options']['admin_only'] and !is_admin()) {
            return new \Piwigo\Ws\PwgError(401, 'Access denied');
        }

        if (!$this->isAuthorizedMethodForAPIKEY()) {
            return new \Piwigo\Ws\PwgError(401, 'Access denied');
        }

        // parameter check and data correction
        $signature = $method['signature'];
        $missing_params = [];

        foreach ($signature as $name => $options) {
            $flags = $options['flags'];

            // parameter not provided in the request
            if (!array_key_exists($name, $params)) {
                if (!self::hasFlag($flags, WS_PARAM_OPTIONAL)) {
                    $missing_params[] = $name;
                } elseif (array_key_exists('default', $options)) {
                    $params[$name] = $options['default'];
                    if (self::hasFlag($flags, WS_PARAM_FORCE_ARRAY)) {
                        self::makeArrayParam($params[$name]);
                    }
                }
            }
            // parameter provided but empty
            elseif ($params[$name] === '' and !self::hasFlag($flags, WS_PARAM_OPTIONAL)) {
                $missing_params[] = $name;
            }
            // parameter provided - do some basic checks
            else {
                $the_param = $params[$name];

                if (is_array($the_param) and !self::hasFlag($flags, WS_PARAM_ACCEPT_ARRAY)) {
                    return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, $name.' must be scalar');
                }

                if (self::hasFlag($flags, WS_PARAM_FORCE_ARRAY)) {
                    self::makeArrayParam($the_param);
                }

                if ($options['type'] > 0) {
                    if (($ret = self::checkType($the_param, $options['type'], $name)) !== null) {
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
            return new \Piwigo\Ws\PwgError(WS_ERR_MISSING_PARAM, 'Missing parameters: '.implode(',', $missing_params));
        }

        $result = trigger_change('ws_invoke_allowed', true, $methodName, $params);
        // A handler may return PwgError to block invocation; true means allowed
        if ($result !== true) {
            return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_METHOD, 'Method invocation not allowed');
        }

        if (!empty($method['include'])) {
            include_once($method['include']);
        }
        $callback = $method['callback'];
        if (!is_callable($callback)) {
            return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_METHOD, 'Invalid method callback');
        }
        return call_user_func_array($callback, [$params, &$this]);
    }

    /**
     * WS reflection method implementation: lists all available methods
     */
    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public static function ws_getMethodList(array $params, PwgServer &$service): array
    {
        $methods = array_filter(
            $service->_methods,
            fn (array $m): bool => empty($m['options']['hidden'])
        );
        return ['methods' => new \Piwigo\Ws\PwgNamedArray(array_keys($methods), 'method') ];
    }

    /**
     * WS reflection method implementation: gets information about a given method
     */
    /**
     * @param array<string,mixed> $params
     * @return array<mixed>|\Piwigo\Ws\PwgError
     */
    public static function ws_getMethodDetails(array $params, PwgServer &$service): \Piwigo\Ws\PwgError|array
    {
        $methodName = $params['methodName'];
        if (!is_string($methodName)) {
            return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, 'methodName must be a string');
        }

        if (!$service->hasMethod($methodName)) {
            return new \Piwigo\Ws\PwgError(WS_ERR_INVALID_PARAM, 'Requested method does not exist');
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
        // if the request is made with an API key (via header or session API key),
        // we check whether the requested method is on the
        // list of prohibited methods (\Piwigo\Config\Config::apiKeyForbiddenMethods()) for API keys
        // if it is, access is refused (false)
        if (
            defined('PWG_API_KEY_REQUEST')
            or (isset($_SESSION['connected_with']) and 'ws_session_login_api_key' === $_SESSION['connected_with'])
        ) {
            if (in_array($_REQUEST['method'], \Piwigo\Config\Config::apiKeyForbiddenMethods())) {
                return false;
            }
        }

        return true;
    }
}
