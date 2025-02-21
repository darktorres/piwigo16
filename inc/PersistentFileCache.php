<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
  Implementation of a persistent cache using files.
*/
class PersistentFileCache extends PersistentCache
{
  private $dir;

  function __construct()
  {
    global $conf;
    $this->dir = PHPWG_ROOT_PATH.$conf['data_location'].'cache/';
  }

  function get($key, &$value)
  {
    $loaded = @file_get_contents($this->dir.$key.'.cache');
    if ($loaded !== false && ($loaded=unserialize($loaded)) !== false)
    {
      if ($loaded['expire'] > time())
      {
        $value = $loaded['data'];
        return true;
      }
    }
    return false;
  }

  function set($key, $value, $lifetime=null)
  {
    if ($lifetime === null)
    {
      $lifetime = $this->default_lifetime;
    }

    if (rand() % 97 == 0)
    {
      $this->purge(false);
    }

    $serialized = serialize( array(
        'expire' => time() + $lifetime,
        'data' => $value
      ));

    if (false === @file_put_contents($this->dir.$key.'.cache', $serialized))
    {
      mkgetdir($this->dir, MKGETDIR_DEFAULT&~MKGETDIR_DIE_ON_ERROR);
      if (false === @file_put_contents($this->dir.$key.'.cache', $serialized))
      {
        return false;
      }
    }
    return true;
  }

  function purge($all)
  {
    $files = glob($this->dir.'*.cache');
    if (empty($files))
    {
      return;
    }

    $limit = time() - $this->default_lifetime;
    foreach ($files as $file)
    {
      if ($all || @filemtime($file) < $limit)
        @unlink($file);
    }
  }

}

?>