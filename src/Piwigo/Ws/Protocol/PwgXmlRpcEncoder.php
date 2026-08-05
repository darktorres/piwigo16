<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

use Override;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

/**
 * mixed values/params throughout are by design -- see the parent class's
 * own docblock.
 */
final class PwgXmlRpcEncoder extends PwgResponseEncoder
{
    #[Override]
    public function encodeResponse($response): string
    {
        if ($response instanceof PwgError) {
            $code = $response->code();
            $msg = htmlspecialchars($response->message());
            $ret = <<<EOD
<methodResponse>
  <fault>
    <value>
      <struct>
        <member>
          <name>faultCode</name>
          <value><int>{$code}</int></value>
        </member>
        <member>
          <name>faultString</name>
          <value><string>{$msg}</string></value>
        </member>
      </struct>
    </value>
  </fault>
</methodResponse>
EOD;
            return $ret;
        }

        parent::flattenResponse($response);
        $ret = self::xmlrpcEncode($response);
        $ret = <<<EOD
<methodResponse>
  <params>
    <param>
      <value>
        {$ret}
      </value>
    </param>
  </params>
</methodResponse>
EOD;
        return $ret;
    }

    #[Override]
    public function getContentType(): string
    {
        return 'text/xml';
    }

    private static function xmlrpcEncode(mixed $data): string
    {
        switch (gettype($data)) {
            case 'boolean':
                return '<boolean>' . ($data ? '1' : '0') . '</boolean>';
            case 'integer':
                return '<int>' . $data . '</int>';
            case 'double':
                return '<double>' . (string) $data . '</double>';
            case 'string':
                return '<string>' . htmlspecialchars($data) . '</string>';
            case 'object':
            case 'array':
                $data = is_object($data) ? get_object_vars($data) : $data;
                $is_array = range(0, count($data) - 1) === array_keys($data);
                if ($is_array) {
                    $return = '<array><data>' . "\n";
                    foreach ($data as $item) {
                        $return .= '  <value>' . self::xmlrpcEncode($item) . "</value>\n";
                    }
                    $return .= '</data></array>';
                } else {
                    $return = '<struct>' . "\n";
                    foreach ($data as $name => $value) {
                        $name = htmlspecialchars((string) $name);
                        $return .= "  <member><name>{$name}</name><value>";
                        $return .= self::xmlrpcEncode($value) . "</value></member>\n";
                    }
                    $return .= '</struct>';
                }
                return $return;
        }

        return '';
    }
}
