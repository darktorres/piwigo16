<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/** Represents a single word or quoted phrase to be searched.*/
class QSingleToken
{
  var $is_single = true;
  var $modifier;
  var $term; /* the actual word/phrase string*/
  var $variants = array();
  var $scope;

  var $scope_data;
  var $idx;

  function __construct($term, $modifier, $scope)
  {
    $this->term = $term;
    $this->modifier = $modifier;
    $this->scope = $scope;
  }

  function __toString()
  {
    $s = '';
    if (isset($this->scope))
      $s .= $this->scope->id .':';
    if ($this->modifier & QST_WILDCARD_BEGIN)
      $s .= '*';
    if ($this->modifier & QST_QUOTED)
      $s .= '"';
    $s .= $this->term;
    if ($this->modifier & QST_QUOTED)
      $s .= '"';
    if ($this->modifier & QST_WILDCARD_END)
      $s .= '*';
    return $s;
  }
}

?>
