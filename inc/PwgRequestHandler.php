<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Abstract base class for request handlers.
 */
abstract class PwgRequestHandler
{
  /** Virtual abstract method. Decodes the request (GET or POST) handles the
   * method invocation as well as response sending.
   */
  abstract function handleRequest(&$service);
}

?>
