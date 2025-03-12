<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage rates
 *
 */

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if ($conf['rate'])
{
  $rate_summary = array( 'count'=>0, 'score'=>$picture['current']['rating_score'], 'average'=>null );
  if ( NULL != $rate_summary['score'] )
  {
    $query = '
SELECT COUNT(rate) AS count
     , ROUND(AVG(rate),2) AS average
  FROM '.RATE_TABLE.'
  WHERE element_id = '.$picture['current']['id'].'
;';
		list($rate_summary['count'], $rate_summary['average']) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
  }
  $template->assign('rate_summary', $rate_summary);

  $user_rate = null;
  if ($conf['rate_anonymous'] or functions_user::is_autorize_status(ACCESS_CLASSIC) )
  {
    if ($rate_summary['count']>0)
    {
      $query = 'SELECT rate
      FROM '.RATE_TABLE.'
      WHERE element_id = '.$page['image_id'] . '
      AND user_id = '.$user['id'] ;

      if ( !functions_user::is_autorize_status(ACCESS_CLASSIC) )
      {
        $ip_components = explode('.', $_SERVER['REMOTE_ADDR']);
        if ( count($ip_components)>3 )
        {
          array_pop($ip_components);
        }
        $anonymous_id = implode ('.', $ip_components);
        $query .= ' AND anonymous_id = \''.$anonymous_id . '\'';
      }

      $result = functions_mysqli::pwg_query($query);
      if (functions_mysqli::pwg_db_num_rows($result) > 0)
      {
        $row = functions_mysqli::pwg_db_fetch_assoc($result);
        $user_rate = $row['rate'];
      }
    }

    $template->assign(
        'rating',
        array(
          'F_ACTION' => functions_url::add_url_params(
                        $url_self,
                        array('action'=>'rate')
                      ),
          'USER_RATE'=> $user_rate,
          'marks'    => $conf['rate_items']
        )
      );
  }
}

?>