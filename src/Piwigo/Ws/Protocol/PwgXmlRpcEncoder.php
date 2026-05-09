<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

final class PwgXmlRpcEncoder extends PwgResponseEncoder
{
    #[\Override]
    public function encodeResponse(mixed $response): string
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
        $ret = xmlrpc_encode($response);
        $ret = <<<EOD
<methodResponse>
  <params>
    <param>
      <value>
        $ret
      </value>
    </param>
  </params>
</methodResponse>
EOD;
        return $ret;
    }

    #[\Override]
    public function getContentType(): string
    {
        return 'text/xml';
    }
}
