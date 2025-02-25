<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * PwgError object can be returned from any web service function implementation.
 */
class PwgError
{
  private $_code;
  private $_codeText;

  function __construct($code, $codeText)
  {
    if ($code>=400 and $code<600)
    {
      set_status_header($code, $codeText);
    }

    $this->_code = $code;
    $this->_codeText = $codeText;
  }

  function code() { return $this->_code; }
  function message() { return $this->_codeText; }
}

?>
