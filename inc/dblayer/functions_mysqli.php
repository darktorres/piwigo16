<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\dblayer;

use Exception;
use mysqli;
use mysqli_result;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;

class functions_mysqli
{
  const DB_ENGINE = 'MySQL';
  const REQUIRED_MYSQL_VERSION = '8.4.4';

  const DB_REGEX_OPERATOR = 'REGEXP';
  const DB_RANDOM_FUNCTION = 'RAND';

  const MASS_UPDATES_SKIP_EMPTY = 1;

  /**
   * Connect to database and store MySQLi resource in __$mysqli__ global variable.
   *
   * @param string $host
   *    - localhost
   *    - 1.2.3.4:3405
   *    - /path/to/socket
   * @param string $user
   * @param string $password
   * @param string $database
   *
   * @throws Exception
   */
  static function pwg_db_connect($host, $user, $password, $database)
  {
    global $mysqli;

    $port = null;
    $socket = null;
    
    if (strpos($host, '/') === 0)
    {
      $socket = $host;
      $host = null;
    }
    elseif (strpos($host, ':') !== false)
    {
      list($host, $port) = explode(':', $host);
    }

    $dbname = '';
    
    $mysqli = new mysqli($host, $user, $password, $dbname, $port, $socket);
    if (mysqli_connect_error())
    {
      throw new Exception("Can't connect to server");
    }
    if (!$mysqli->select_db($database))
    {
      throw new Exception('Connection to server succeed, but it was impossible to connect to database');
    }

    // MySQL 5.7 default settings forbid to select a colum that is not in the
    // group by. We've used that in Piwigo, for years. As an immediate solution
    // we can remove this constraint in the current MySQL session.
    list($sql_mode_current) = self::pwg_db_fetch_row(self::pwg_query('SELECT @@SESSION.sql_mode'));

    // remove ONLY_FULL_GROUP_BY from the list
    $sql_mode_altered = implode(',', array_diff(explode(',', $sql_mode_current), array('ONLY_FULL_GROUP_BY')));

    if ($sql_mode_altered != $sql_mode_current)
    {
      self::pwg_query("SET SESSION sql_mode='".$sql_mode_altered."'");
    }
  }

  /**
   * Set charset for database connection.
   */
  static function pwg_db_check_charset() 
  {
    global $mysqli;

    $db_charset = 'utf8';
    if (defined('DB_CHARSET') and DB_CHARSET != '')
    {
      $db_charset = DB_CHARSET;
    }
    $mysqli->set_charset($db_charset);
  }

  /**
   * Check MySQL version. Can call fatal_error().
   */
  static function pwg_db_check_version()
  {
    $current_mysql = self::pwg_get_db_version();
    if (version_compare($current_mysql, self::REQUIRED_MYSQL_VERSION, '<'))
    {
      functions_html::fatal_error(
        sprintf(
          'your MySQL version is too old, you have "%s" and you need at least "%s"',
          $current_mysql,
          self::REQUIRED_MYSQL_VERSION
          )
        );
    }
  }

  /**
   * Get Mysql Version.
   *
   * @return string
   */
  static function pwg_get_db_version() 
  {
    global $mysqli;
    
    return $mysqli->server_info;
  }

  /**
   * Execute a query
   *
   * @param string $query
   * @return mysqli_result|bool
   */
  static function pwg_query($query)
  {
    global $mysqli, $conf, $page, $debug, $t2;

    $start = microtime(true);

    $log_file = dirname(__DIR__, 2) . '/_data/sql/mysqli.sql';
    $log_dir = dirname($log_file);

    if (! is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    file_put_contents($log_file, $query . "\n\n", FILE_APPEND | LOCK_EX);

    ($result = $mysqli->query($query)) or self::my_error($query, $conf['die_on_sql_error']);

    $time = microtime(true) - $start;

    if (!isset($page['count_queries']))
    {
      $page['count_queries'] = 0;
      $page['queries_time'] = 0;
    }

    $page['count_queries']++;
    $page['queries_time']+= $time;

    if ($conf['show_queries'])
    {
      $output = '';
      $output.= '<pre>['.$page['count_queries'].'] ';
      $output.= "\n".$query;
      $output.= "\n".'(this query time : ';
      $output.= '<b>'.number_format($time, 3, '.', ' ').' s)</b>';
      $output.= "\n".'(total SQL time  : ';
      $output.= number_format($page['queries_time'], 3, '.', ' ').' s)';
      $output.= "\n".'(total time      : ';
      $output.= number_format( ($time+$start-$t2), 3, '.', ' ').' s)';
      if ( $result!=null and preg_match('/\s*SELECT\s+/i',$query) )
      {
        $output.= "\n".'(num rows        : ';
        $output.= self::pwg_db_num_rows($result).' )';
      }
      elseif ( $result!=null
        and preg_match('/\s*INSERT|UPDATE|REPLACE|DELETE\s+/i',$query) )
      {
        $output.= "\n".'(affected rows   : ';
        $output.= self::pwg_db_changes().' )';
      }
      $output.= "</pre>\n";

      $debug .= $output;
    }

    return $result;
  }

  /**
   * Get max value plus one of a particular column.
   *
   * @param string $column
   * @param string $table
   * @param int
   */
  static function pwg_db_nextval($column, $table)
  {
    $query = '
  SELECT IF(MAX('.$column.')+1 IS NULL, 1, MAX('.$column.')+1)
    FROM '.$table;
    list($next) = self::pwg_db_fetch_row(self::pwg_query($query));

    return $next;
  }

  static function pwg_db_changes() 
  {
    global $mysqli;
    
    return $mysqli->affected_rows;
  }

  static function pwg_db_num_rows($result) 
  {
    return $result->num_rows;
  }

  static function pwg_db_fetch_array($result)
  {
    return $result->fetch_array();
  }

  static function pwg_db_fetch_assoc($result)
  {
    return $result->fetch_assoc();
  }

  static function pwg_db_fetch_row($result)
  {
    return $result->fetch_row();
  }

  static function pwg_db_fetch_object($result)
  {
    return $result->fetch_object();
  }

  static function pwg_db_free_result($result) 
  {
    return $result->free_result();
  }

  static function pwg_db_real_escape_string($s)
  {
    global $mysqli;
    
    return isset($s) ? $mysqli->real_escape_string($s) : null;
  }

  static function pwg_db_insert_id()
  {
    global $mysqli;
    
    return $mysqli->insert_id;
  }

  static function pwg_db_errno()
  {
    global $mysqli;
    
    return $mysqli->errno;
  }

  static function pwg_db_error()
  {
    global $mysqli;
    
    return $mysqli->error;
  }

  static function pwg_db_close()
  {
    global $mysqli;
    
    return $mysqli->close();
  }

  /**
   * Updates multiple lines in a table.
   *
   * @param string $tablename
   * @param array $dbfields - contains 'primary' and 'update' arrays
   * @param array $datas - indexed by column names
   * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
   */
  static function mass_updates($tablename, $dbfields, $datas, $flags=0)
  {
    if (count($datas) == 0)
    {
      return;
    }

    // we use the multi table update or N update queries
    if (count($datas) < 10)
    {
      foreach ($datas as $data)
      {
        $is_first = true;

        $query = '
  UPDATE '.self::protect_column_name($tablename).'
    SET ';

        foreach ($dbfields['update'] as $key)
        {
          $separator = $is_first ? '' : ",\n    ";

          if (isset($data[$key]) and $data[$key] != '')
          {
            $query.= $separator.self::protect_column_name($key).' = \''.$data[$key].'\'';
          }
          else
          {
            if ($flags & self::MASS_UPDATES_SKIP_EMPTY)
            {
              continue; // next field
            }
            $query.= $separator.self::protect_column_name($key).' = NULL';
          }
          $is_first = false;
        }

        if (!$is_first)
        {// only if one field at least updated
          $is_first = true;

          $query.= '
    WHERE ';
          foreach ($dbfields['primary'] as $key)
          {
            if (!$is_first)
            {
              $query.= ' AND ';
            }
            if (isset($data[$key]))
            {
              $query.= self::protect_column_name($key).' = \''.$data[$key].'\'';
            }
            else
            {
              $query.= self::protect_column_name($key).' IS NULL';
            }
            $is_first = false;
          }

          self::pwg_query($query);
        }
      } // foreach update
    } // if count<X
    else
    {
      // creation of the temporary table
      $result = self::pwg_query('SHOW FULL COLUMNS FROM '.self::protect_column_name($tablename));
      $columns = array();
      $all_fields = array_merge($dbfields['primary'], $dbfields['update']);

      while ($row = self::pwg_db_fetch_assoc($result))
      {
        if (in_array($row['Field'], $all_fields))
        {
          $column = '`'.$row['Field'].'`';
          $column.= ' '.$row['Type'];

          $nullable = true;
          if (!isset($row['Null']) or $row['Null'] == '' or $row['Null']=='NO')
          {
            $column.= ' NOT NULL';
            $nullable = false;
          }
          if (isset($row['Default']))
          {
            $column.= " default '".$row['Default']."'";
          }
          elseif ($nullable)
          {
            $column.= " default NULL";
          }
          if (isset($row['Collation']) and $row['Collation'] != 'NULL')
          {
            $column.= " collate '".$row['Collation']."'";
          }
          $columns[] = $column;
        }
      }

      $temporary_tablename = $tablename.'_'.functions::micro_seconds();

      $query = '
  CREATE TABLE '.$temporary_tablename.'
  (
    '.implode(",\n  ", $columns).',
    UNIQUE KEY the_key ('.implode(',', $dbfields['primary']).')
  )';

      self::pwg_query($query);
      self::mass_inserts($temporary_tablename, $all_fields, $datas);

      if ($flags & self::MASS_UPDATES_SKIP_EMPTY)
        $func_set = function($s) { return "t1.$s = IFNULL(t2.$s, t1.$s)"; };
      else
        $func_set = function($s) { return "t1.$s = t2.$s"; };

      // update of table by joining with temporary table
      $query = '
  UPDATE '.self::protect_column_name($tablename).' AS t1, '.$temporary_tablename.' AS t2
    SET '.
        implode(
          "\n    , ",
          array_map($func_set,$dbfields['update'])
          ).'
    WHERE '.
        implode(
          "\n    AND ",
          array_map(
            function($s) { return "t1.$s = t2.$s"; },
            $dbfields['primary']
            )
          );
      self::pwg_query($query);

      self::pwg_query('DROP TABLE '.$temporary_tablename);
    }
  }

  /**
   * Updates one line in a table.
   *
   * @param string $tablename
   * @param array $datas
   * @param array $where
   * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
   */
  static function single_update($tablename, $datas, $where, $flags=0)
  {
    if (count($datas) == 0)
    {
      return;
    }

    $is_first = true;

    $query = '
  UPDATE '.self::protect_column_name($tablename).'
    SET ';

    foreach ($datas as $key => $value)
    {
      $separator = $is_first ? '' : ",\n    ";

      if (isset($value) and $value !== '')
      {
        $query.= $separator.self::protect_column_name($key).' = \''.$value.'\'';
      }
      else
      {
        if ($flags & self::MASS_UPDATES_SKIP_EMPTY)
        {
          continue; // next field
        }
        $query.= $separator.self::protect_column_name($key).' = NULL';
      }
      $is_first = false;
    }

    if (!$is_first)
    {// only if one field at least updated
      $is_first = true;

      $query.= '
    WHERE ';

      foreach ($where as $key => $value)
      {
        if (!$is_first)
        {
          $query.= ' AND ';
        }
        if (isset($value))
        {
          $query.= self::protect_column_name($key).' = \''.$value.'\'';
        }
        else
        {
          $query.= self::protect_column_name($key).' IS NULL';
        }
        $is_first = false;
      }

      self::pwg_query($query);
    }
  }

  /**
   * Inserts multiple lines in a table.
   *
   * @param string $table_name
   * @param array $dbfields - fields from $datas which will be used
   * @param array $datas
   * @param array $options
   *    - boolean ignore - use "INSERT IGNORE"
   */
  static function mass_inserts($table_name, $dbfields, $datas, $options=array())
  {
    $ignore = '';
    if (isset($options['ignore']) and $options['ignore'])
    {
      $ignore = 'IGNORE';
    }
    
    if (count($datas) != 0)
    {
      $first = true;

      $query = 'SHOW VARIABLES LIKE \'max_allowed_packet\'';
      list(, $packet_size) = self::pwg_db_fetch_row(self::pwg_query($query));
      $packet_size = $packet_size - 2000; // The last list of values MUST not exceed 2000 character*/
      $query = '';

      foreach ($datas as $insert)
      {
        if (strlen($query) >= $packet_size)
        {
          self::pwg_query($query);
          $first = true;
        }

        if ($first)
        {
          $query = '
  INSERT '.$ignore.' INTO '.self::protect_column_name($table_name).'
    ('.implode(',', array_map(self::protect_column_name(...), $dbfields)).')
    VALUES';
          $first = false;
        }
        else
        {
          $query .= '
    , ';
        }

        $query .= '(';
        foreach ($dbfields as $field_id => $dbfield)
        {
          if ($field_id > 0)
          {
            $query .= ',';
          }

          if (!isset($insert[$dbfield]) or $insert[$dbfield] === '')
          {
            $query .= 'NULL';
          }
          else
          {
            $query .= "'".$insert[$dbfield]."'";
          }
        }
        $query .= ')';
      }

      self::pwg_query($query);
    }
  }

  /**
   * Inserts one line in a table.
   *
   * @param string $table_name
   * @param array $data
   * @param array $options
   *    - boolean ignore - use "INSERT IGNORE"
   */
  static function single_insert($table_name, $data, $options=array())
  {
    $ignore = '';
    if (isset($options['ignore']) and $options['ignore'])
    {
      $ignore = 'IGNORE';
    }

    if (count($data) != 0)
    {
      $query = '
  INSERT '.$ignore.' INTO '.self::protect_column_name($table_name).'
    ('.implode(',', array_map(self::protect_column_name(...), array_keys($data))).')
    VALUES';

      $query .= '(';
      $is_first = true;
      foreach ($data as $key => $value)
      {
        if (!$is_first)
        {
          $query .= ',';
        }
        else
        {
          $is_first = false;
        }
        
        if ($value === '' || is_null($value))
        {
          $query .= 'NULL';
        }
        else
        {
          $query .= "'".$value."'";
        }
      }
      $query .= ')';
      
      self::pwg_query($query);
    }
  }

  static function protect_column_name($column_name)
  {
    if ('`' != $column_name[0])
    {
      $column_name = '`'.$column_name.'`';
    }

    return $column_name;
  }

  /**
   * Do maintenance on all Piwigo tables
   */
  static function do_maintenance_all_tables()
  {
    global $prefixeTable, $page;

    $all_tables = array();

    // List all tables
    $query = 'SHOW TABLES LIKE \''.$prefixeTable.'%\'';
    $result = self::pwg_query($query);
    while ($row = self::pwg_db_fetch_row($result))
    {
      $all_tables[] = $row[0];
    }

    // Repair all tables
    $query = 'REPAIR TABLE '.implode(', ', $all_tables);
    $mysqli_rc = self::pwg_query($query);

    // Re-Order all tables
    foreach ($all_tables as $table_name)
    {
      $all_primary_key = array();

      $query = 'DESC '.$table_name.';';
      $result = self::pwg_query($query);
      while ($row = self::pwg_db_fetch_assoc($result))
      {
        if ($row['Key'] == 'PRI')
        {
          $all_primary_key[] = $row['Field'];
        }
      }

      if (count($all_primary_key) != 0)
      {
        $query = 'ALTER TABLE '.$table_name.' ORDER BY '.implode(', ', $all_primary_key).';';
        $mysqli_rc = $mysqli_rc && self::pwg_query($query);
      }
    }

    // Optimize all tables
    $query = 'OPTIMIZE TABLE '.implode(', ', $all_tables);
    $mysqli_rc = $mysqli_rc && self::pwg_query($query);
    if ($mysqli_rc)
    {
      $page['infos'][] = functions::l10n('All optimizations have been successfully completed.');
    }
    else
    {
      $page['errors'][] = functions::l10n('Optimizations have been completed with some errors.');
    }
  }

  static function pwg_db_concat($array)
  {
    $string = implode(',', $array);
    return 'CONCAT('. $string.')';
  }

  static function pwg_db_concat_ws($array, $separator)
  {
    $string = implode(',', $array);
    return 'CONCAT_WS(\''.$separator.'\','. $string.')';
  }

  static function pwg_db_cast_to_text($string)
  {
    return $string;
  }

  /**
   * Returns an array containing the possible values of an enum field.
   *
   * @param string $table
   * @param string $field
   * @return string[]
   */
  static function get_enums($table, $field)
  {
    $result = self::pwg_query('DESC '.$table);
    while ($row = self::pwg_db_fetch_assoc($result))
    {
      if ($row['Field'] == $field)
      {
        // parse enum('blue','green','black')
        $options = explode(',', substr($row['Type'], 5, -1));
        foreach ($options as $i => $option)
        {
          $options[$i] = str_replace("'", '',$option);
        }
      }
    }

    self::pwg_db_free_result($result);
    return $options;
  }

  /**
   * Checks if a variable is equivalent to true or false.
   *
   * @param mixed $input
   * @return bool
   */
  static function get_boolean($input)
  {
    if ('false' === strtolower($input))
    {
      return false;
    }

    return (bool)$input;
  }

  /**
   * Returns string 'true' or 'false' if the given var is boolean.
   * If the input is another type, it is not changed.
   *
   * @param mixed $var
   * @return mixed
   */
  static function boolean_to_string($var)
  {
    if (is_bool($var))
    {
      return $var ? 'true' : 'false';
    }
    else
    {
      return $var;
    }
  }

  static function pwg_db_get_recent_period_expression($period, $date='CURRENT_DATE')
  {
    if ($date!='CURRENT_DATE')
    {
      $date = '\''.$date.'\'';
    }

    return 'SUBDATE('.$date.',INTERVAL '.$period.' DAY)';
  }

  static function pwg_db_get_recent_period($period, $date='CURRENT_DATE')
  {
    $query = '
  SELECT '.self::pwg_db_get_recent_period_expression($period);
    list($d) = self::pwg_db_fetch_row(self::pwg_query($query));

    return $d;
  }

  static function pwg_db_get_flood_period_expression($seconds)
  {
    return 'SUBDATE(NOW(), INTERVAL '.$seconds.' SECOND)';
  }

  static function pwg_db_get_hour($date) 
  {
    return 'HOUR('.$date.')';
  }

  static function pwg_db_get_date_YYYYMM($date)
  {
    return 'DATE_FORMAT('.$date.', \'%Y%m\')';
  }

  static function pwg_db_get_date_MMDD($date)
  {
    return 'DATE_FORMAT('.$date.', \'%m%d\')';
  }

  static function pwg_db_get_year($date)
  {
    return 'YEAR('.$date.')';
  }

  static function pwg_db_get_month($date)
  {
    return 'MONTH('.$date.')';
  }

  static function pwg_db_get_week($date, $mode=null)
  {
    if ($mode)
    {
      return 'WEEK('.$date.', '.$mode.')';
    }
    else
    {
      return 'WEEK('.$date.')';
    }
  }

  static function pwg_db_get_dayofmonth($date)
  {
    return 'DAYOFMONTH('.$date.')';
  }

  static function pwg_db_get_dayofweek($date)
  {
    return 'DAYOFWEEK('.$date.')';
  }

  static function pwg_db_get_weekday($date)
  {
    return 'WEEKDAY('.$date.')';
  }

  static function pwg_db_date_to_ts($date) 
  {
    return 'UNIX_TIMESTAMP('.$date.')';
  }

  /**
   * Returns (or send to standard output) the message concerning the 
   * error occured for the last mysql query.
   */
  static function my_error($header, $die)
  {
    global $mysqli;
    
    $error = "[mysql error ".$mysqli->errno.'] '.$mysqli->error."\n";
    $error .= $header;

    if ($die)
    {
      functions_html::fatal_error($error);
    }
    echo("<pre>");
    trigger_error($error, E_USER_WARNING);
    echo("</pre>");
  }

  /**
   * Builds an data array from a SQL query.
   * Depending on $key_name and $value_name it can return :
   *
   *    - an array of arrays of all fields (key=null, value=null)
   *        array(
   *          array('id'=>1, 'name'=>'DSC8956', ...),
   *          array('id'=>2, 'name'=>'DSC8957', ...),
   *          ...
   *          )
   *
   *    - an array of a single field (key=null, value='...')
   *        array('DSC8956', 'DSC8957', ...)
   *
   *    - an associative array of array of all fields (key='...', value=null)
   *        array(
   *          'DSC8956' => array('id'=>1, 'name'=>'DSC8956', ...),
   *          'DSC8957' => array('id'=>2, 'name'=>'DSC8957', ...),
   *          ...
   *          )
   *
   *    - an associative array of a single field (key='...', value='...')
   *        array(
   *          'DSC8956' => 1,
   *          'DSC8957' => 2,
   *          ...
   *          )
   *
   * @since 2.6
   *
   * @param string $query
   * @param string $key_name
   * @param string $value_name
   * @return array
   */
  static function query2array($query, $key_name=null, $value_name=null)
  {
    $result = self::pwg_query($query);
    $data = array();

    if (isset($key_name))
    {
      if (isset($value_name))
      {
        while ($row = $result->fetch_assoc())
          $data[ $row[$key_name] ] = $row[$value_name];
      }
      else
      {
        while ($row = $result->fetch_assoc())
          $data[ $row[$key_name] ] = $row;
      }
    }
    else
    {
      if (isset($value_name))
      {
        while ($row = $result->fetch_assoc())
          $data[] = $row[$value_name];
      }
      else
      {
        while ($row = $result->fetch_assoc())
          $data[] = $row;
      }
    }

    return $data;
  }
}

?>
