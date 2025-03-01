<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Used to declare maintenance methods of a theme.
 */
class ThemeMaintain
{
  /** @var string $theme_id */
  protected $theme_id;

  /**
   * @param string $id
   */
  function __construct($id)
  {
    $this->theme_id = $id;
  }

  /**
   * @param string $theme_version
   * @param array $errors - used to return error messages
   */
  function activate($theme_version, &$errors=array()) {}

  function deactivate() {}

  function delete() {}
}

?>