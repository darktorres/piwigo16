<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\menubar
 */


/**
 * Represents a menu block ready for display in the BlockManager object.
 */
class DisplayBlock
{
  /** @var RegisteredBlock */
  protected $_registeredBlock;
  /** @var int */
  protected $_position;
  /** @var string */
  protected $_title;

  /** @var mixed */
  public $data;
  /** @var string */
  public $template;
  /** @var string */
  public $raw_content;

  public $id;

  /**
   * @param RegisteredBlock $block
   */
  public function __construct($block)
  {
    $this->_registeredBlock = $block;
  }

  /**
   * @return RegisteredBlock
   */
  public function get_block()
  {
    return $this->_registeredBlock;
  }

  /**
   * @return int
   */
  public function get_position()
  {
    return $this->_position;
  }

  /**
   * @param int $position
   */
  public function set_position($position)
  {
    $this->_position = $position;
  }

  /**
   * @return string
   */
  public function get_title()
  {
    if (isset($this->_title))
    {
      return $this->_title;
    }
    else
    {
      return $this->_registeredBlock->get_name();
    }
  }

  /**
   * @param string
   */
  public function set_title($title)
  {
    $this->_title = $title;
  }
}

?>