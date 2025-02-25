<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_protocols;


class PwgXmlWriter
{
  var $_indent;
  var $_indentStr;

  var $_elementStack;
  var $_lastTagOpen;
  var $_indentLevel;

  var $_encodedXml;

  function __construct()
  {
    $this->_elementStack = array();
    $this->_lastTagOpen = false;
    $this->_indentLevel = 0;

    $this->_encodedXml = '';
    $this->_indent = true;
    $this->_indentStr = "\t";
  }

  function &getOutput()
  {
    return $this->_encodedXml;
  }


  function start_element($name)
  {
    $this->_end_prev(false);
    if (!empty($this->_elementStack))
    {
      $this->_eol_indent();
    }
    $this->_indentLevel++;
    $this->_indent();
    $diff = ord($name[0])-ord('0');
    if ($diff>=0 && $diff<=9)
    {
      $name='_'.$name;
    }
    $this->_output( '<'.$name );
    $this->_lastTagOpen = true;
    $this->_elementStack[] = $name;
  }

  function end_element($x)
  {
    $close_tag = $this->_end_prev(true);
    $name = array_pop( $this->_elementStack );
    if ($close_tag)
    {
      $this->_indentLevel--;
      $this->_indent();
//      $this->_eol_indent();
      $this->_output('</'.$name.">");
    }
  }

  function write_content($value)
  {
    $this->_end_prev(false);
    $value = (string)$value;
    $this->_output( htmlspecialchars( $value ) );
  }

  function write_cdata($value)
  {
    $this->_end_prev(false);
    $value = (string)$value;
    $this->_output(
      '<![CDATA['
      . str_replace(']]>', ']]&gt;', $value)
      . ']]>' );
  }

  function write_attribute($name, $value)
  {
    $this->_output(' '.$name.'="'.$this->encode_attribute($value).'"');
  }

  function encode_attribute($value)
  {
    return htmlspecialchars( (string)$value);
  }

  function _end_prev($done)
  {
    $ret = true;
    if ($this->_lastTagOpen)
    {
      if ($done)
      {
        $this->_indentLevel--;
        $this->_output( ' />' );
        //$this->_eol_indent();
        $ret = false;
      }
      else
      {
        $this->_output( '>' );
      }
      $this->_lastTagOpen = false;
    }
    return $ret;
  }

  function _eol_indent()
  {
    if ($this->_indent)
      $this->_output("\n");
  }

  function _indent()
  {
    if ($this->_indent and
        $this->_indentLevel > count($this->_elementStack) )
    {
      $this->_output(
        str_repeat( $this->_indentStr, count($this->_elementStack) )
       );
    }
  }

  function _output($raw_content)
  {
    $this->_encodedXml .= $raw_content;
  }
}

?>
