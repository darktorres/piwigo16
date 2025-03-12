<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_protocols;

use Piwigo\inc\functions;
use Piwigo\inc\PwgError;
use Piwigo\inc\PwgNamedArray;
use Piwigo\inc\PwgNamedStruct;
use Piwigo\inc\PwgResponseEncoder;

class PwgRestEncoder extends PwgResponseEncoder
{
  private $_writer;
  function encodeResponse($response)
  {
    if ($response instanceof PwgError)
    {
      $ret = '<?xml version="1.0"?>
<rsp stat="fail">
	<err code="'.$response->code().'" msg="'.htmlspecialchars($response->message()).'" />
</rsp>';
      return $ret;
    }

    $this->_writer = new PwgXmlWriter();
    $this->encode($response);
    $ret = $this->_writer->getOutput();
    $ret = '<?xml version="1.0" encoding="'.functions::get_pwg_charset().'" ?>
<rsp stat="ok">
'.$ret.'
</rsp>';

    return $ret;
  }

  function getContentType()
  {
    return 'text/xml';
  }

  function encode_array($data, $itemName, $xml_attributes=array())
  {
    foreach ($data as $item)
    {
      $this->_writer->start_element( $itemName );
      $this->encode($item, $xml_attributes);
      $this->_writer->end_element( $itemName );
    }
  }

  function encode_struct($data, $skip_underscore, $xml_attributes=array())
  {
    foreach ($data as $name => $value)
    {
      if (is_numeric($name))
        continue;
      if ($skip_underscore and $name[0]=='_')
        continue;
      if ( is_null($value) )
        continue; // null means we dont put it
      if ( $name==WS_XML_ATTRIBUTES)
      {
        foreach ($value as $attr_name => $attr_value)
        {
          $this->_writer->write_attribute($attr_name, $attr_value);
        }
        unset($data[$name]);
      }
      else if ( isset($xml_attributes[$name]) )
      {
        $this->_writer->write_attribute($name, $value);
        unset($data[$name]);
      }
    }

    foreach ($data as $name => $value)
    {
      if (is_numeric($name))
        continue;
      if ($skip_underscore and $name[0]=='_')
        continue;
      if ( is_null($value) )
        continue; // null means we dont put it
      $this->_writer->start_element($name);
      $this->encode($value);
      $this->_writer->end_element($name);
    }
  }

  function encode($data, $xml_attributes=array() )
  {
    switch (gettype($data))
    {
      case 'null':
      case 'NULL':
        $this->_writer->write_content('');
        break;
      case 'boolean':
        $this->_writer->write_content($data ? '1' : '0');
        break;
      case 'integer':
      case 'double':
        $this->_writer->write_content($data);
        break;
      case 'string':
        $this->_writer->write_content($data);
        break;
      case 'array':
        $is_array = range(0, count($data) - 1) === array_keys($data);
        if ($is_array)
        {
          $this->encode_array($data, 'item' );
        }
        else
        {
          $this->encode_struct($data, false, $xml_attributes);
        }
        break;
      case 'object':
        if ($data instanceof PwgNamedArray) {
          $this->encode_array($data->_content, $data->_itemName, $data->_xmlAttributes);
        } elseif ($data instanceof PwgNamedStruct) {
          $this->encode_struct($data->_content, false, $data->_xmlAttributes);
        } else {
          $this->encode_struct(get_object_vars($data), true);
        }
        break;
      default:
        trigger_error("Invalid type ". gettype($data)." ".(is_object($data) ? $data::class : ""), E_USER_WARNING );
    }
  }
}

?>
