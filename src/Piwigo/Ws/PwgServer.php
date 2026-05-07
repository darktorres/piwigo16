<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Exception\ConfigException;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;

/**
 * @phpstan-type WsParamDef array{flags: int, type: int, default?: mixed, maxValue?: int|float, info?: string, chooseList?: list<mixed>}
 * @phpstan-type WsMethod array{callback: mixed, description: string, signature: array<string, WsParamDef>, options: array<string, mixed>}
 */
class PwgServer
{
    private ?PwgRequestHandler $_requestHandler = null;
    private string $_requestFormat = '';
    private ?PwgResponseEncoder $_responseEncoder = null;
    private string $_responseFormat = '';

    /** @var array<string, WsMethod> */
    private array $_methods = [];

    /** @var array<string, MethodDefinition> */
    private array $_methodDefs = [];

    public function __construct()
    {
    }

    /** @return array<string, WsMethod> */
    public function getMethods(): array
    {
        return $this->_methods;
    }

    /** @return array<string, MethodDefinition> */
    public function getMethodDefs(): array
    {
        return $this->_methodDefs;
    }

    /**
     * Typed registration path — stores the MethodDefinition for SpecBuilder / #22
     * and also normalizes to the internal WsMethod array so invoke() works unchanged.
     */
    public function register(MethodDefinition $def): void
    {
        $this->_methodDefs[$def->name] = $def;

        $signature = [];
        foreach ($def->params as $param) {
            $signature[$param->name] = $param->toWsParamDef();
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

        $this->_methods[$def->name] = [
            'callback'    => $def->callback,
            'description' => $def->description,
            'signature'   => $signature,
            'options'     => $options,
        ];
    }

    public function getResponseFormat(): string
    {
        return $this->_responseFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setHandler(string $requestFormat, ?PwgRequestHandler $requestHandler): void
    {
        $this->_requestHandler = $requestHandler;
        $this->_requestFormat = $requestFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setEncoder(string $responseFormat, ?PwgResponseEncoder $encoder): void
    {
        $this->_responseEncoder = $encoder;
        $this->_responseFormat = $responseFormat;
    }

    /**
     * Runs the web service call (handler and response encoder should have been
     * created)
     */
    public function run(): void
    {
        if (is_null($this->_responseEncoder)) {
            ServiceLocator::get(HtmlService::class)->setStatusHeader(400);
            if (!headers_sent()) {
                header('Content-Type: text/plain');
            }
            echo('Cannot process your request. Unknown response format.
Request format: '.$this->_requestFormat.' Response format: '.$this->_responseFormat."\n");
            var_export($this);
            throw new ConfigException('Cannot process request: unknown response format');
        }

        $handler = $this->_requestHandler;
        if ($handler === null) {
            $this->sendResponse(new PwgError(400, 'Unknown request format'));
            return;
        }

        $this->populateMethods();
        $handler->handleRequest($this);
    }

    /**
     * Registers reflection methods, triggers the ws_add_methods event, and sorts.
     * Called by run() and by ws.php when serving the OpenAPI spec without a handler.
     */
    public function populateMethods(): void
    {
        $this->register(new MethodDefinition(
            name:        'reflection.getMethodList',
            callback:    self::wsGetMethodList(...),
            description: 'Lists all available web service methods.',
            tags:        ['reflection'],
        ));
        $this->register(new MethodDefinition(
            name:        'reflection.getMethodDetails',
            callback:    self::wsGetMethodDetails(...),
            description: 'Returns details about a specific method.',
            params:      [ParamDefinition::required('methodName')],
            tags:        ['reflection'],
        ));

        WsMethodRegistrar::register($this);
        EventDispatcher::notify('ws_add_methods', [&$this]);
        uksort($this->_methods, strnatcmp(...));
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

        if (!headers_sent()) {
            header('Content-Type: '.$contentType.'; charset='.ServiceLocator::get(StringUtil::class)->getPwgCharset());
        }
        print_r($encodedResponse);
        EventDispatcher::notify('sendResponse', $encodedResponse);
    }

    public function hasMethod(string $methodName): bool
    {
        return isset($this->_methods[$methodName]);
    }

    public function getMethodDescription(string $methodName): string
    {
        return isset($this->_methods[$methodName]) ? $this->_methods[$methodName]['description'] : '';
    }

    /** @return array<string, WsParamDef> */
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

    public static function checkType(mixed &$param, int $type, string $name): ?PwgError
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
                        return new PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain booleans');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_INT, $opts)) === false) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain'.$msg.' integers');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                foreach ($param as &$value) {
                    if (
                        ($value = filter_var($value, FILTER_VALIDATE_FLOAT)) === false
                        or (isset($opts['options']['min_range']) and $value < $opts['options']['min_range'])
                    ) {
                        return new PwgError(WS_ERR_INVALID_PARAM, $name.' must only contain'.$msg.' floats');
                    }
                }
                unset($value);
            }
        } elseif ($param !== '') {
            if (self::hasFlag($type, WS_TYPE_BOOL)) {
                if (($param = filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name.' must be a boolean');
                }
            } elseif (self::hasFlag($type, WS_TYPE_INT)) {
                if (($param = filter_var($param, FILTER_VALIDATE_INT, $opts)) === false) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name.' must be an'.$msg.' integer');
                }
            } elseif (self::hasFlag($type, WS_TYPE_FLOAT)) {
                if (
                    ($param = filter_var($param, FILTER_VALIDATE_FLOAT)) === false
                    or (isset($opts['options']['min_range']) and $param < $opts['options']['min_range'])
                ) {
                    return new PwgError(WS_ERR_INVALID_PARAM, $name.' must be a'.$msg.' float');
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
     * @param string $methodName the name of the method to invoke
     * @param array<mixed> $params array of parameters to pass to the invoked method
     */
    public function invoke(string $methodName, array $params): mixed
    {
        if (!isset($this->_methods[$methodName])) {
            return new PwgError(WS_ERR_INVALID_METHOD, 'Method name is not valid');
        }
        $method = $this->_methods[$methodName];

        if (isset($method['options']['post_only']) and $method['options']['post_only'] and !self::isPost()) {
            return new PwgError(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and $method['options']['admin_only'] and !PermissionService::get()->isAdmin()) {
            return new PwgError(401, 'Access denied');
        }

        if (!$this->isAuthorizedMethodForAPIKEY()) {
            return new PwgError(401, 'Access denied');
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
                    return new PwgError(WS_ERR_INVALID_PARAM, $name.' must be scalar');
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
            return new PwgError(WS_ERR_MISSING_PARAM, 'Missing parameters: '.implode(',', $missing_params));
        }

        $result = EventDispatcher::dispatch('ws_invoke_allowed', true, $methodName, $params);
        // A handler may return PwgError to block invocation; true means allowed
        if ($result !== true) {
            return new PwgError(WS_ERR_INVALID_METHOD, 'Method invocation not allowed');
        }

        $callback = $method['callback'];
        if (!is_callable($callback)) {
            return new PwgError(WS_ERR_INVALID_METHOD, 'Invalid method callback');
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
    public static function wsGetMethodList(array $params, self &$service): array
    {
        $methods = array_filter(
            $service->_methods,
            fn (array $m): bool => empty($m['options']['hidden'])
        );
        return ['methods' => new PwgNamedArray(array_keys($methods), 'method') ];
    }

    /**
     * WS reflection method implementation: gets information about a given method
     */
    /**
     * @param array<string,mixed> $params
     * @return array<mixed>|PwgError
     */
    public static function wsGetMethodDetails(array $params, self &$service): PwgError|array
    {
        $methodName = $params['methodName'];
        if (!is_string($methodName)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'methodName must be a string');
        }

        if (!$service->hasMethod($methodName)) {
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

    public static function boot(): void
    {
        if (PwgServerRegistry::isInitialized()) {
            return;
        }
        if (!defined('WS_PARAM_OPTIONAL')) {
            define('WS_PARAM_ACCEPT_ARRAY', WsParam::AcceptArray->value);
            define('WS_PARAM_FORCE_ARRAY', WsParam::ForceArray->value);
            define('WS_PARAM_OPTIONAL', WsParam::Optional->value);
            define('WS_TYPE_BOOL', WsType::Bool->value);
            define('WS_TYPE_INT', WsType::Int->value);
            define('WS_TYPE_FLOAT', WsType::Float->value);
            define('WS_TYPE_POSITIVE', WsType::Positive->value);
            define('WS_TYPE_NOTNULL', WsType::NotNull->value);
            define('WS_TYPE_ID', WsType::Id->value);
            define('WS_ERR_INVALID_METHOD', 501);
            define('WS_ERR_MISSING_PARAM', 1002);
            define('WS_ERR_INVALID_PARAM', 1003);
            define('WS_XML_ATTRIBUTES', 'attributes_xml_');
        }

        EventDispatcher::addListener('ws_invoke_allowed', static fn (mixed $res, string $methodName, array $params): mixed => ServiceLocator::get(WsHelper::class)->isInvokeAllowed($res, $methodName, $params), EventDispatcher::NEUTRAL_PRIORITY);

        $requestFormat = 'rest';
        $responseFormat = null;

        if (isset($_GET['format'])) {
            $responseFormat = is_string($_GET['format']) ? $_GET['format'] : null;
        }

        if (!isset($responseFormat)) {
            $responseFormat = $requestFormat;
        }
        $responseFormat = (string) $responseFormat;

        $server = new self();
        PwgServerRegistry::set($server);
        $GLOBALS['service'] = $server;

        $handler = null;
        switch ($requestFormat) {
            case 'rest':
                $handler = new PwgRestRequestHandler();
                break;
        }
        $server->setHandler($requestFormat, $handler);

        $encoder = null;
        switch ($responseFormat) {
            case 'rest':
                $encoder = new PwgRestEncoder();
                break;
            case 'php':
                $encoder = new PwgSerialPhpEncoder();
                break;
            case 'json':
                $encoder = new PwgJsonEncoder();
                break;
            case 'xmlrpc':
                $encoder = new PwgXmlRpcEncoder();
                break;
        }
        $server->setEncoder($responseFormat, $encoder);

        UrlService::get()->setMakeFullUrl();
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
            if (in_array($_REQUEST['method'], Config::apiKeyForbiddenMethods())) {
                return false;
            }
        }

        return true;
    }
}
