# `@` Error Suppression Audit

Every `@` operator in the PHP code, grouped by file. Each box represents one suppression site — replace with explicit handling (`isset()` / null-coalescing / try-catch / scoped `error_reporting`) or document the reason.

Total: 254 suppressions across 73 files (excludes `vendor/` and commented-out code).

## Entry points / bootstrap

### `action.php`
- [ ] L169 — `@is_readable($file)`
- [ ] L172 — `@filesize($file)`
- [ ] L215 — `@set_time_limit(0)`
- [ ] L224 — `@readfile($file)`

### `i.php`
- [ ] L24 — `@include(PHPWG_ROOT_PATH.'local/config/config.inc.php')`
- [ ] L29 — `@include(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'config/database.inc.php')`
- [ ] L70 — `@mkdir($dir, ...)`
- [ ] L77 — `@file_put_contents($file, 'Not allowed!')`
- [ ] L324 — `@filemtime($candidate_path)`
- [ ] L450 — `@filemtime($page['src_path'])`
- [ ] L456 — `@filemtime($page['derivative_path'])`
- [ ] L537 — `@set_time_limit(0)`
- [ ] L634 — `@chmod($page['derivative_path'], 0644)`

### `index.php`
- [ ] L242 — `@$page['qsearch_details']['matching_cats_no_images']`
- [ ] L243 — `@$page['qsearch_details']['matching_cats']`
- [ ] L254 — `@$page['qsearch_details']['matching_tags']`

### `install.php`
- [ ] L34 — `@include(...)`
- [ ] L82 — `@file_exists($config_file)`
- [ ] L105 — `@substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2)`
- [ ] L214 — `@umask(0111)`
- [ ] L216 — `@fopen($config_file, 'w')`
- [ ] L221 — `@fopen(...)`
- [ ] L223 — `@fputs($fh, ...)`
- [ ] L224 — `@fclose($fh)`
- [ ] L236 — `@fputs($fp, ...)`
- [ ] L237 — `@fclose($fp)`

### `install/upgrade_1.3.1.php`
- [ ] L553 — `@file_get_contents($config_file)`

### `install/upgrade_1.5.0.php`
- [ ] L307 — `@include(...)`

### `profile.php`
- [ ] L390 — `@$userdata['email']`

### `upgrade.php`
- [ ] L22 — `@ini_set('opcache.enable', 0)`
- [ ] L29 — `@include(...)`
- [ ] L33 — `@file_get_contents($config_file)`
- [ ] L150 — `@substr($httpAccLang, 0, 2)`

### `upgrade_feed.php`
- [ ] L13 — `@include(...)`

## `admin/`

### `admin/albums.php`
- [ ] L179 — `@explode(',', (string) $user['forbidden_categories'])`
- [ ] L256 — `@$subcats_of[$uppercat_id][]`

### `admin/batch_manager_unit.php`
- [ ] L65 — `@$_POST[$desc_key]`
- [ ] L67 — `@$_POST[$desc_key]`
- [ ] L384 — `@$row['width']` and `@$row['height']`

### `admin/cat_list.php`
- [ ] L249 — `@$subcats_of[$uppercat_id][]`

### `admin/cat_modify.php`
- [ ] L175 — `@htmlspecialchars((string) $category['name'])`
- [ ] L176 — `@htmlspecialchars((string) $category['comment'])`

### `admin/configuration.php`
- [ ] L367 — `@include(...)`
- [ ] L369 — `@include(...)`
- [ ] L494 — `@unserialize(...)`

### `admin/history.php`
- [ ] L131 — `@$form_param['user_name']`
- [ ] L135 — `@$form['start']`
- [ ] L136 — `@$form['end']`
- [ ] L147 — `@$form_param['user_name']`

### `admin/intro.php`
- [ ] L249 — `@$activity_last_weeks[$week][$day_nb]['details'][...]`
- [ ] L251 — `@$activity_last_weeks[$week][$day_nb]['date']`
- [ ] L386 — `@$data_storage[$type]['total']['filesize']`
- [ ] L389 — `@$data_storage[$type]['details'][strtoupper($ext)]`
- [ ] L409 — `@$data_storage[$type]['total']['filesize']`
- [ ] L412 — `@$data_storage[$type]['details'][strtoupper($ext)]`
- [ ] L423 — `@$data_storage['Cache']['total']['filesize']`

### `admin/maintenance_actions.php`
- [ ] L211 — `@explode("\r\n", $result)`
- [ ] L346 — `@$gd_info['GD Version']`

### `admin/maintenance_env.php`
- [ ] L198 — `@explode("\r\n", $result)`

### `admin/permalinks.php`
- [ ] L55 — `@$_GET[$get_param]`

### `admin/picture_modify.php`
- [ ] L92 — `@$_POST[$field]`
- [ ] L230 — `@$row['name']`
- [ ] L234 — `@$row['width']` and `@$row['height']`
- [ ] L238 — `@$row['filesize']`

### `admin/plugins_installed.php`
- [ ] L123 — `@$fs_plugin['author uri']`

### `admin/rating.php`
- [ ] L166 — `@$_GET['users']`

### `admin/site_manager.php`
- [ ] L159 — `@$sites_detail[(string)$site_id]['nb_categories']`
- [ ] L160 — `@$sites_detail[(string)$site_id]['nb_images']`

### `admin/stats.php`
- [ ] L170 — `@$months[$date->format('Y/m/1')][]`
- [ ] L175 — `@$months[$actual_date->format('Y/m/1')][]`

### `admin/tags.php`
- [ ] L139 — `@$tag_counters[$tag_id_key]`
- [ ] L141 — `@$tag_counters[$tag_id_key]`

### `admin/themes_installed.php`
- [ ] L74 — `@$fs_theme['author uri']`
- [ ] L75 — `@$fs_theme['parent']`
- [ ] L78 — `@$fs_theme['admin_uri']`
- [ ] L142 — `@$a['IS_DEFAULT']`
- [ ] L145 — `@$b['IS_DEFAULT']`

### `admin/user_list.php`
- [ ] L253 — `@include(...)`
- [ ] L255 — `@include(...)`

### `admin/include/functions.php`
- [ ] L2330 — `@explode(',', (string) $user['forbidden_categories'])`
- [ ] L2355 — `@file_get_contents($src)`
- [ ] L2357 — `@fwrite($dest, $content)`
- [ ] L2388 — `@curl_init()`
- [ ] L2391 — `@curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, false)`
- [ ] L2392 — `@curl_setopt($ch, CURLOPT_PROXY, ...)`
- [ ] L2395 — `@curl_setopt($ch, CURLOPT_PROXYUSERPWD, ...)`
- [ ] L2400 — `@curl_setopt($ch, CURLOPT_URL, $src)`
- [ ] L2402 — `@curl_setopt($ch, CURLOPT_HEADER, true)`
- [ ] L2404 — `@curl_setopt($ch, CURLOPT_USERAGENT, ...)`
- [ ] L2406 — `@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true)`
- [ ] L2408 — `@curl_setopt($ch, CURLOPT_POST, true)`
- [ ] L2409 — `@curl_setopt($ch, CURLOPT_POSTFIELDS, $request)`
- [ ] L2411 — `@curl_exec($ch)`
- [ ] L2412 — `@curl_getinfo($ch, CURLINFO_HEADER_SIZE)`
- [ ] L2413 — `@curl_getinfo($ch, CURLINFO_HTTP_CODE)`
- [ ] L2420 — `@fwrite($dest, $content)`
- [ ] L2439 — `@stream_context_create($opts)`
- [ ] L2440 — `@file_get_contents($src, false, $context)`
- [ ] L2442 — `@fwrite($dest, $content)`
- [ ] L2459 — `@fsockopen($host, 80, $errno, $errstr, 5)`
- [ ] L2508 — `@fwrite($dest, $line)`
- [ ] L2882 — `@opendir(PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR)`
- [ ] L2928 — `@rmdir($path)`
- [ ] L2964 — `@unlink($file)`
- [ ] L3008 — `@unlink($pathfile)`
- [ ] L3015 — `@rmdir($path)`
- [ ] L3019 — `@mkgetdir($trash_path, ...)`
- [ ] L3023 — `@rename($path, $r)`
- [ ] L3334 — `@$msizes[$size_code] += filesize(...)`
- [ ] L3338 — `@$msizes[$size_key] += $value`
- [ ] L3520 — `@$gd_info['GD Version']`

### `admin/include/functions_history.inc.php`
- [ ] L336 — `@$time_tokens[1]`
- [ ] L337 — `@$time_tokens[2]`
- [ ] L338 — `@$time_tokens[3]`

### `admin/include/functions_install.inc.php`
- [ ] L114 — `@new mysqli($h, $user, $pass, '', $port, $socket)`

### `admin/include/functions_metadata.php`
- [ ] L142 — `@filesize($file)`
- [ ] L153 — `@getimagesize($file)`
- [ ] L198 — `@getimagesize($file)`

### `admin/include/functions_upgrade.php`
- [ ] L209 — `@get_magic_quotes_gpc()`

### `admin/include/functions_upload.inc.php`
- [ ] L282 — `@chmod($file_path, 0644)`
- [ ] L478 — `@chmod($format_path, 0644)`
- [ ] L561 — `@exec($exec, $returnarray)`
- [ ] L606 — `@exec($exec, $returnarray)`
- [ ] L655 — `@exec($exec, $returnarray)`
- [ ] L721 — `@exec($ffmpeg.' 2>&1', $FO, $FS)`
- [ ] L731 — `@exec($avconv.' 2>&1', $AO, $AS)`
- [ ] L784 — `@exec($exec, $returnarray)`
- [ ] L839 — `@exec($exec, $returnarray)`
- [ ] L857 — `@mkdir($directory, 0777, $recursive)`
- [ ] L864 — `@chmod($directory, 0777)`
- [ ] L994 — `@chmod($upload_dir, 0777)`

## `include/`

### `include/category_cats.inc.php`
- [ ] L82 — `@$row['max_date_last']` and `@$row['date_last']`
- [ ] L297 — `@$category['comment']`

### `include/category_default.inc.php`
- [ ] L96 — `@$nb_comments_of[$row['id']]`

### `include/common.inc.php`
- [ ] L34 — `@get_magic_quotes_gpc()`
- [ ] L74 — `@include(...)`
- [ ] L78 — `@include(...)`
- [ ] L88 — `@ini_set('error_reporting', ...)`
- [ ] L90 — `@ini_set('display_errors', true)`
- [ ] L95 — `@ini_set('session.gc_divisor', 100)`
- [ ] L96 — `@ini_set('session.gc_probability', ...)`
- [ ] L296 — `@header('Retry-After: 900')`

### `include/derivative_std_params.inc.php`
- [ ] L149 — `@self::$custom[$key]`
- [ ] L169 — `@unserialize(...)`

### `include/functions.inc.php`
- [ ] L151 — `@mkdir($dir, ..., $recursive)`
- [ ] L159 — `@file_put_contents($file, 'deny from all')`
- [ ] L163 — `@file_put_contents($file, 'Not allowed!')`
- [ ] L477 — `@$page['section']`
- [ ] L1650 — `@$options['return']`
- [ ] L1655 — `@$options['return']`
- [ ] L1685 — `@$options['no_fallback']`
- [ ] L1695 — `@$options['local']`
- [ ] L1707 — `@$options['return']`
- [ ] L1710 — `@include(str_replace($selected_language, ..., $source_file))`
- [ ] L1716 — `@include($source_file)`
- [ ] L1737 — `@include(str_replace($selected_language, $parent_language, $source_file))`
- [ ] L1745 — `@file_get_contents($source_file)`
- [ ] L1788 — `@file_put_contents($file, 'Not allowed!')`
- [ ] L1817 — `@$key` (passed to explode)
- [ ] L2364 — `@unserialize($result)`
- [ ] L2373 — `@$official_exts[$idxCat][$archiveDir]`
- [ ] L2481 — `@$piwigo_infos['themes_usage'][$theme_used] += $counter`
- [ ] L2563 — `@$piwigo_infos['updates'][]`
- [ ] L2611 — `@$apps[$app_name]['counter'] += $activity['counter']`
- [ ] L2767 — `@file($info_file_path)`

### `include/functions_category.inc.php`
- [ ] L115 — `@$row['max_date_last']` and `@$row['date_last']`
- [ ] L141 — `@$page['category']['id']`
- [ ] L737 — `@$cat_ids[$uppercat]++`
- [ ] L788 — `@$cats[$idx]['count_images']`

### `include/functions_comment.inc.php`
- [ ] L129 — `@$key`

### `include/functions_html.inc.php`
- [ ] L359 — `@$bt[$i]['class']`
- [ ] L373 — `@set_status_header(500)`

### `include/functions_mail.inc.php`
- [ ] L637 — `@$args['Bcc']`
- [ ] L680 — `@$args['email_format']`

### `include/functions_metadata.inc.php`
- [ ] L28 — `@getimagesize($filename, $imginfo)`
- [ ] L124 — `@exif_read_data($filename)`

### `include/functions_search.inc.php`
- [ ] L822 — `@$page['search_details'][__FUNCTION__][$cache_key]`
- [ ] L877 — `@$str[0]`
- [ ] L880 — `@$str[0]`
- [ ] L978 — `@$str[0]`
- [ ] L981 — `@$str[0]`

### `include/functions_url.inc.php`
- [ ] L20 — `@$page['root_path']`
- [ ] L290 — `@$params['section']`
- [ ] L523 — `@$tokens[$next_token]` ('tags')
- [ ] L557 — `@$tokens[$next_token]` ('favorites')
- [ ] L560 — `@$tokens[$next_token]` ('most_visited')
- [ ] L563 — `@$tokens[$next_token]` ('best_rated')
- [ ] L566 — `@$tokens[$next_token]` ('recent_pics')
- [ ] L569 — `@$tokens[$next_token]` ('recent_cats')
- [ ] L572 — `@$tokens[$next_token]` ('search')
- [ ] L576 — `@$tokens[$next_token]` (psk regex)
- [ ] L578 — `@$tokens[$next_token]` (digit regex)
- [ ] L586 — `@$tokens[$next_token]` ('list')

### `include/functions_user.inc.php`
- [ ] L419 — `@header('Retry-After: 900')`
- [ ] L812 — `@$_SERVER['HTTP_ACCEPT_LANGUAGE']`
- [ ] L1033 — `@$cookie[0]`
- [ ] L1034 — `@$cookie[1]`
- [ ] L1035 — `@$cookie[1]`
- [ ] L1036 — `@$cookie[1]`
- [ ] L2205 — `@$params['level']`
- [ ] L2248 — `@$params['recent_period']`
- [ ] L2252 — `@$params['expand']`
- [ ] L2257 — `@$params['show_nb_comments']`
- [ ] L2262 — `@$params['show_nb_hits']`
- [ ] L2267 — `@$params['enabled_high']`
- [ ] L2624 — `@pwg_mail(...)`

### `include/menubar.inc.php`
- [ ] L35 — `@$page['section']`

### `include/picture_comment.inc.php`
- [ ] L237 — `@$comment_action`

### `include/search_filters.inc.php`
- [ ] L199 — `@$pre_counters[$threshold]++`
- [ ] L298 — `@$pre_counters[$threshold]++`
- [ ] L588 — `@$filesizes[sprintf('%.1f', $fs_val / 1024)]++`

### `include/section_init.inc.php`
- [ ] L324 — `@$page['super_order_by']`
- [ ] L563 — `@$page['hit_by']['cat_url_name']`
- [ ] L567 — `@$page['hit_by']['cat_permalink']`

### `include/ws_core.inc.php`
- [ ] L271 — `@header('Content-Type: text/plain')`
- [ ] L273 — `@$this->_requestFormat` and `@$this->_responseFormat`
- [ ] L316 — `@header('Content-Type: ...; charset=...')`

### `include/ws_functions/pwg.groups.php`
- [ ] L193 — `@$params['is_default']`

### `include/ws_functions/pwg.images.php`
- [ ] L914 — `@$search['fields']['date_posted']['custom'][]`
- [ ] L924 — `@$search['fields']['date_created']['preset']`
- [ ] L966 — `@$search['fields']['date_created']['custom'][]`
- [ ] L1708 — `@fopen("{$filePath}.part", ...)`
- [ ] L1721 — `@fopen($filesFileTmpName, 'rb')`
- [ ] L1725 — `@fopen('php://input', 'rb')`
- [ ] L1734 — `@fclose($out)`
- [ ] L1735 — `@fclose($in)`
- [ ] L2005 — `@unlink($output_filepath)`
- [ ] L2222 — `@$unique_filenames_db[$filename_wo_ext][]`

### `include/ws_functions/pwg.php`
- [ ] L100 — `@filemtime($derivative->get_path())`
- [ ] L218 — `@exec('du -sk '.$path_cache, $return_array_cache)`
- [ ] L237 — `@$msizes[derivative_to_url($size_type)]`
- [ ] L246 — `@exec('du -sk '.$path_template_c, $return_array_template_c)`
- [ ] L572 — `@unserialize($row_details_str)`
- [ ] L1037 — `@intval($image_infos[$line_image_id_str]['filesize'] ?? 0)`
- [ ] L1149 — `@$sorted_members[$user_name] += 1`

### `include/ws_functions/pwg.users.php`
- [ ] L904 — `@pwg_mail($user_lost_email, $email_params)`

## `src/Piwigo/`

### `src/Piwigo/Admin/Image/image_ext_imagick.php`
- [ ] L23 — `@$_SERVER['SCRIPT_FILENAME']`
- [ ] L25 — `@putenv('MAGICK_THREAD_LIMIT=1')`
- [ ] L45 — `@exec($command, $returnarray)`
- [ ] L186 — `@exec($exec, $returnarray)`

### `src/Piwigo/Admin/Image/pwg_image.php`
- [ ] L277 — `@exif_read_data($source_filepath)`
- [ ] L387 — `@exec(...' -version', $returnarray)`

### `src/Piwigo/Admin/languages.php`
- [ ] L176 — `@uasort($this->fs_languages, name_compare(...))`
- [ ] L211 — `@unserialize($result)`
- [ ] L264 — `@unserialize($result)`
- [ ] L278 — `@uasort($this->server_languages, ...)`
- [ ] L301 — `@fopen($archive, 'wb')`
- [ ] L377 — `@unlink($path)`
- [ ] L403 — `@unlink($archive)`

### `src/Piwigo/Admin/plugins.php`
- [ ] L377 — `@unserialize($result)`
- [ ] L459 — `@unserialize($result)`
- [ ] L508 — `@unserialize($result)`
- [ ] L585 — `@fopen($archive, 'wb')`
- [ ] L654 — `@unlink($path)`
- [ ] L680 — `@unlink($archive)`

### `src/Piwigo/Admin/themes.php`
- [ ] L434 — `@unserialize($result)`
- [ ] L486 — `@unserialize($result)`
- [ ] L539 — `@fopen($archive, 'wb')`
- [ ] L611 — `@unlink($path)`
- [ ] L637 — `@unlink($archive)`

### `src/Piwigo/Admin/updates.php`
- [ ] L55 — `@fetchRemote(PHPWG_URL.'/download/all_versions.php?...', $result)`
- [ ] L56 — `@explode("\n", $result)`
- [ ] L96 — `@fetchRemote($url, $result)`
- [ ] L260 — `@unserialize($result)`
- [ ] L314 — `@unserialize($result)`
- [ ] L468 — `@unlink($path)`
- [ ] L510 — `@mkgetdir($path)`
- [ ] L514 — `@fopen($filename, 'w')`
- [ ] L518 — `@fetchRemote(PHPWG_URL.'/download/dlcounter.php?...', $result)`
- [ ] L519 — `@unserialize($result)`
- [ ] L528 — `@fwrite($zip, base64_decode(...))`
- [ ] L536 — `@fclose($zip)`
- [ ] L539 — `@filesize($filename)`
- [ ] L560 — `@chmod(PHPWG_ROOT_PATH.$extractFilename, 0777)`

### `src/Piwigo/Auth/PwgBase32.php`
- [ ] L98 — `@self::$flippedMap[@$input[$i + $j]]` (two suppressions on one line)

### `src/Piwigo/Cache/PersistentFileCache.php`
- [ ] L21 — `@file_get_contents($this->dir.$key.'.cache')`
- [ ] L49 — `@file_put_contents($this->dir.$key.'.cache', $serialized)`
- [ ] L68 — `@filemtime($file)`
- [ ] L69 — `@unlink($file)`

### `src/Piwigo/Core/Logger.php`
- [ ] L302 — `@filemtime($file)`
- [ ] L303 — `@unlink($file)`

### `src/Piwigo/Image/DerivativeImage.php`
- [ ] L192 — `@filemtime(PHPWG_ROOT_PATH.$rel_path)`

### `src/Piwigo/Image/ImageStdParams.php`
- [ ] L101 — `@self::$custom[$key]`
- [ ] L121 — `@unserialize(...)`

### `src/Piwigo/Image/SrcImage.php`
- [ ] L41 — `@strtolower(get_extension($file))`
- [ ] L54 — `@getimagesize(PHPWG_ROOT_PATH.$this->rel_path)`

### `src/Piwigo/Search/QDateRangeScope.php`
- [ ] L22 — `@$str[0]`
- [ ] L25 — `@$str[0]`

### `src/Piwigo/Search/QNumericRangeScope.php`
- [ ] L23 — `@$str[0]`
- [ ] L26 — `@$str[0]`

### `src/Piwigo/Template/FileCombiner.php`
- [ ] L121 — `@chmod(PHPWG_ROOT_PATH.$file, 0644)`

### `src/Piwigo/Ws/PwgServer.php`
- [ ] L62 — `@header('Content-Type: text/plain')`
- [ ] L64 — `@$this->_requestFormat` and `@$this->_responseFormat`
- [ ] L102 — `@header('Content-Type: ...; charset=...')`

## `tests/`

### `tests/Unit/Cache/PersistentFileCacheTest.php`
- [ ] L45 — `@rmdir($this->cacheDir)`
- [ ] L46 — `@rmdir($this->tmpRoot)`

## `tools/`

### `tools/triggers_list.php`
- [ ] L1024 — `@$trigger['infos']`
