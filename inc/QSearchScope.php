<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
  var $id;
  var $aliases;
  var $is_text;
  var $nullable;

  function __construct($id, $aliases, $nullable=false, $is_text=true)
  {
    $this->id = $id;
    $this->aliases = $aliases;
    $this->is_text = $is_text;
    $this->nullable =$nullable;
  }

  function parse($token)
  {
    if (!$this->nullable && 0==strlen($token->term))
      return false;
    return true;
  }

  function process_char(&$ch, &$crt_token)
  {
    return false;
  }
}

?>
