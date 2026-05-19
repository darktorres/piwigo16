<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Event\Ws\SendResponse;
use Piwigo\Event\Ws\WsInvokeAllowed;
use Piwigo\Event\Ws\WsMethodsRegistering;
use Piwigo\Exception\ConfigException;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ApiKeyAuthRegistry;
use Piwigo\Session\Session;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @phpstan-type WsParamDef array{flags: int, type: int, default?: mixed, maxValue?: int|float, info?: string, chooseList?: list<mixed>}
 * @phpstan-type WsMethod array{callback: mixed, handlerClass: ?string, description: string, signature: array<string, WsParamDef>, options: array<string, mixed>}
 */
final class PwgServer
{
    private ?PwgRequestHandler $_requestHandler = null;
    private string $_requestFormat = '';
    private ?PwgResponseEncoder $_responseEncoder = null;
    private string $_responseFormat = '';

    /** @var array<string, WsMethod> */
    private array $_methods = [];

    /** @var array<string, MethodDefinition> */
    private array $_methodDefs = [];

    public function __construct(
        private readonly HtmlService $htmlService,
        private readonly PermissionService $permissionService,
        private readonly Session $session,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
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
            'callback'     => $def->callback,
            'handlerClass' => $def->handlerClass,
            'description'  => $def->description,
            'signature'    => $signature,
            'options'      => $options,
        ];
    }

    public function getResponseFormat(): string
    {
        return $this->_responseFormat;
    }

    /**
     *  Initializes the request handler.
     */
    public function setHandler(string $requestFormat, PwgRestRequestHandler $requestHandler): void
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
            $this->htmlService->setStatusHeader(400);
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
     * Registers reflection methods then dispatches WsMethodsRegistering
     * (B11). The core WsMethodRegistrar subscribes with priority 100 so
     * it lands first; any plugin subscriber may run before or after by
     * choosing a higher or lower priority.
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

        $this->dispatcher->dispatch(new WsMethodsRegistering($this));
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

        if (!headers_sent() && $response instanceof PwgError) {
            $code = $response->code();
            if ($code !== null && $code >= 400 && $code < 600) {
                $this->htmlService->setStatusHeader($code, $response->message());
            }
        }

        $encodedResponse = $this->_responseEncoder->encodeResponse($response);
        $contentType = $this->_responseEncoder->getContentType();

        if (!headers_sent()) {
            header('Content-Type: '.$contentType.'; charset=utf-8');
        }
        print_r($encodedResponse);
        $this->dispatcher->dispatch(new SendResponse(is_string($encodedResponse) ? $encodedResponse : ''));
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
        $opts = ['options' => []];
        $msg = '';
        if (self::hasFlag($type, WsType::Positive->value | WsType::NotNull->value)) {
            $opts['options']['min_range'] = 1;
            $msg = ' positive and not null';
        } elseif (self::hasFlag($type, WsType::Positive->value)) {
            $opts['options']['min_range'] = 0;
            $msg = ' positive';
        }

        if (is_array($param)) {
            if (self::hasFlag($type, WsType::Bool->value)) {
                foreach ($param as &$value) {
                    if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                        return new PwgError(WsError::InvalidParam->value, $name.' must only contain booleans');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WsType::Int->value)) {
                foreach ($param as &$value) {
                    // Psalm's filter_var return-type provider checks
                    // `min_range` against Type::getNumeric() but rejects
                    // plain int values (only `numeric-string|float`
                    // pass). Filter_var accepts int at runtime — this is
                    // a Psalm-internal mismatch; suppress just the
                    // option-type check.
                    /** @psalm-suppress InvalidArgument */
                    if (($value = filter_var($value, FILTER_VALIDATE_INT, $opts)) === false) {
                        return new PwgError(WsError::InvalidParam->value, $name.' must only contain'.$msg.' integers');
                    }
                }
                unset($value);
            } elseif (self::hasFlag($type, WsType::Float->value)) {
                foreach ($param as &$value) {
                    if (
                        ($value = filter_var($value, FILTER_VALIDATE_FLOAT)) === false
                        or (isset($opts['options']['min_range']) and $value < $opts['options']['min_range'])
                    ) {
                        return new PwgError(WsError::InvalidParam->value, $name.' must only contain'.$msg.' floats');
                    }
                }
                unset($value);
            }
        } elseif ($param !== '') {
            if (self::hasFlag($type, WsType::Bool->value)) {
                if (($param = filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
                    return new PwgError(WsError::InvalidParam->value, $name.' must be a boolean');
                }
            } elseif (self::hasFlag($type, WsType::Int->value)) {
                // See above: Psalm's filter_var option-type check rejects
                // int values for `min_range` despite filter_var accepting
                // them at runtime.
                /** @psalm-suppress InvalidArgument */
                if (($param = filter_var($param, FILTER_VALIDATE_INT, $opts)) === false) {
                    return new PwgError(WsError::InvalidParam->value, $name.' must be an'.$msg.' integer');
                }
            } elseif (self::hasFlag($type, WsType::Float->value)) {
                if (
                    ($param = filter_var($param, FILTER_VALIDATE_FLOAT)) === false
                    or (isset($opts['options']['min_range']) and $param < $opts['options']['min_range'])
                ) {
                    return new PwgError(WsError::InvalidParam->value, $name.' must be a'.$msg.' float');
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
            return new PwgError(WsError::InvalidMethod->value, 'Method name is not valid');
        }
        $method = $this->_methods[$methodName];

        if (isset($method['options']['post_only']) and $method['options']['post_only'] and !self::isPost()) {
            return new PwgError(405, 'This method requires HTTP POST');
        }

        if (isset($method['options']['admin_only']) and $method['options']['admin_only'] and !$this->permissionService->isAdmin()) {
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
                if (!self::hasFlag($flags, WsParam::Optional->value)) {
                    $missing_params[] = $name;
                } elseif (array_key_exists('default', $options)) {
                    $params[$name] = $options['default'];
                    if (self::hasFlag($flags, WsParam::ForceArray->value)) {
                        self::makeArrayParam($params[$name]);
                    }
                }
            }
            // parameter provided but empty
            elseif ($params[$name] === '' and !self::hasFlag($flags, WsParam::Optional->value)) {
                $missing_params[] = $name;
            }
            // parameter provided - do some basic checks
            else {
                $the_param = $params[$name];

                if (is_array($the_param) and !self::hasFlag($flags, WsParam::AcceptArray->value)) {
                    return new PwgError(WsError::InvalidParam->value, $name.' must be scalar');
                }

                if (self::hasFlag($flags, WsParam::ForceArray->value)) {
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
            return new PwgError(WsError::MissingParam->value, 'Missing parameters: '.implode(',', $missing_params));
        }

        $invokeEvent = new WsInvokeAllowed(true, $methodName, $params);
        $this->dispatcher->dispatch($invokeEvent);
        // A subscriber may flip $value to a PwgError to block invocation;
        // true means allowed.
        if ($invokeEvent->value !== true) {
            return new PwgError(WsError::InvalidMethod->value, 'Method invocation not allowed');
        }

        $handlerClass = $method['handlerClass'];
        if ($handlerClass !== null) {
            /** @var class-string<WsAction> $handlerClass */
            $handler = Kernel::service($handlerClass);
            if (!is_callable($handler)) {
                return new PwgError(WsError::InvalidMethod->value, 'Handler is not invokable');
            }
            return $handler($params, $this);
        }
        $callback = $method['callback'];
        if (!is_callable($callback)) {
            return new PwgError(WsError::InvalidMethod->value, 'Invalid method callback');
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
            fn (array $m): bool => !isset($m['options']['hidden']) || $m['options']['hidden'] === '' || $m['options']['hidden'] === false || $m['options']['hidden'] === 0
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
            return new PwgError(WsError::InvalidParam->value, 'methodName must be a string');
        }

        if (!$service->hasMethod($methodName)) {
            return new PwgError(WsError::InvalidParam->value, 'Requested method does not exist');
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
              'optional' => self::hasFlag($options['flags'], WsParam::Optional->value),
              'acceptArray' => self::hasFlag($options['flags'], WsParam::AcceptArray->value),
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

            if (self::hasFlag($options['type'], WsType::Bool->value)) {
                $param_data['type'] = 'bool';
            } elseif (self::hasFlag($options['type'], WsType::Int->value)) {
                $param_data['type'] = 'int';
            } elseif (self::hasFlag($options['type'], WsType::Float->value)) {
                $param_data['type'] = 'float';
            }
            if (self::hasFlag($options['type'], WsType::Positive->value)) {
                $param_data['type'] .= ' positive';
            }
            if (self::hasFlag($options['type'], WsType::NotNull->value)) {
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
        // ws_invoke_allowed listener now registers at boot via
        // Piwigo\Listener\WsInvokeAllowedSubscriber.
        $requestFormat = 'rest';
        $responseFormat = null;

        if (isset($_GET['format'])) {
            $responseFormat = is_string($_GET['format']) ? $_GET['format'] : null;
        }

        if (!isset($responseFormat)) {
            $responseFormat = $requestFormat;
        }


        $server = Kernel::service(self::class);
        PwgServerRegistry::set($server);

        switch ($requestFormat) {
            case 'rest':
                $server->setHandler($requestFormat, new PwgRestRequestHandler());
                break;
        }

        $encoder = null;
        switch ($responseFormat) {
            case 'rest':
                $encoder = new PwgRestEncoder();
                break;
            case 'json':
                $encoder = new PwgJsonEncoder();
                break;
        }
        $server->setEncoder($responseFormat, $encoder);

        Kernel::service(UrlService::class)->setMakeFullUrl();
    }

    public function isAuthorizedMethodForAPIKEY(): bool
    {
        // if the request is made with an API key (via header or session API key),
        // we check whether the requested method is on the
        // list of prohibited methods (\Piwigo\Config\Config::apiKeyForbiddenMethods()) for API keys
        // if it is, access is refused (false)
        if (
            ApiKeyAuthRegistry::isApiKeyAuth()
            or 'ws_session_login_api_key' === $this->session->connectedWith
        ) {
            if (in_array($_REQUEST['method'], Config::apiKeyForbiddenMethods())) {
                return false;
            }
        }

        return true;
    }
}
