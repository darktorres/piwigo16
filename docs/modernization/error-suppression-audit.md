# `@` Error Suppression Audit — completed 2026-05-03

All 263 sites across 51 files have been removed (roadmap task #9).
Replacement patterns are documented in
[`ROADMAP-PHP.md`](ROADMAP-PHP.md#9----error-suppression-cleanup).

The list below is preserved as historical reference for what changed.
The lint rule `Piwigo\Tools\PhpStan\NoErrorSuppressionRule` (registered
in `phpstan.neon`) fails on any future `@` use — there is no allowlist.

**Total at start: 263 sites / 51 files.**
**Total now: 0.**

### action.php

- [ ] L169 — `if (!@is_readable($file)) {`
- [ ] L215 — `@set_time_limit(0);`
- [ ] L224 — `@readfile($file);`

### admin/albums.php

- [ ] L179 — `array_fill_keys(@explode(',', (string) $user['forbidden_categories']), 1)`
- [ ] L258 — `@$subcats_of[$uppercat_id][] = $id;`

### admin/batch_manager_unit.php

- [ ] L65 — `$data['comment'] = @$_POST[$desc_key];`
- [ ] L67 — `$desc_val = @$_POST[$desc_key];`
- [ ] L384 — `'DIMENSIONS' => @$row['width'].'x'.@$row['height'].' px',`

### admin/cat_list.php

- [ ] L249 — `@$subcats_of[$uppercat_id][] = $id;`

### admin/cat_modify.php

- [ ] L177 — `'CAT_NAME'    => @htmlspecialchars((string) $category['name']),`
- [ ] L178 — `'CAT_COMMENT' => @htmlspecialchars((string) $category['comment']),`

### admin/configuration.php

- [ ] L382 — `$content = @file_get_contents($real);`
- [ ] L514 — `'picture_informations' => @unserialize(...)`

### admin/history.php

- [ ] L131 — `'USER_NAME' => @$form_param['user_name'],`
- [ ] L135 — `'START' => @$form['start'],`
- [ ] L136 — `'END' => @$form['end'],`
- [ ] L147 — `'filter_user_name' => @$form_param['user_name'],`

### admin/include/functions.php

- [ ] L2333 — `if (in_array($category_id, @explode(',', $forbidden))) {`
- [ ] L2358 — `$content = @file_get_contents($src);`
- [ ] L2360 — `is_resource($dest) ? @fwrite($dest, $content) : ...`
- [ ] L2391 — `$ch = @curl_init();`
- [ ] L2394–2412 — 9× `@curl_setopt(...)` / `@curl_setopt(...)`
- [ ] L2414 — `$content = @curl_exec($ch);`
- [ ] L2415 — `$header_length = @curl_getinfo($ch, CURLINFO_HEADER_SIZE);`
- [ ] L2416 — `$status = @curl_getinfo($ch, CURLINFO_HTTP_CODE);`
- [ ] L2423 — `is_resource($dest) ? @fwrite(...) : ...`
- [ ] L2442 — `$context = @stream_context_create($opts);`
- [ ] L2443 — `$content = @file_get_contents($src, false, $context);`
- [ ] L2445 — `is_resource($dest) ? @fwrite(...) : ...`
- [ ] L2462 — `if (($s = @fsockopen($host, 80, $errno, $errstr, 5)) === false)`
- [ ] L2891 — `if ($contents = @opendir(PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR))`
- [ ] L2937 — `@rmdir($path);`
- [ ] L2973 — `@unlink($file);`
- [ ] L3017 — `@unlink($pathfile);`
- [ ] L3024 — `if (@rmdir($path)) {`
- [ ] L3028 — `@mkgetdir($trash_path, ...)`
- [ ] L3032 — `@rename($path, $r);`
- [ ] L3343 — `@$msizes[$size_code] += filesize(...);`
- [ ] L3347 — `@$msizes[$size_key] += $value;`

### admin/include/functions_history.inc.php

- [ ] L339 — `'month' => @$time_tokens[1],`
- [ ] L340 — `'day'   => @$time_tokens[2],`
- [ ] L341 — `'hour'  => @$time_tokens[3],`

### admin/include/functions_install.inc.php

- [ ] L114 — `$tmp = @new mysqli($h, $user, $pass, '', $port, $socket);`

### admin/include/functions_metadata.php

- [ ] L142 — `$fs = @filesize($file);`
- [ ] L153 — `if ($image_size = @getimagesize($file)) {`
- [ ] L198 — `if ($image_size = @getimagesize($file)) {`

### admin/include/functions_upgrade.php

- [ ] L204 — `if (function_exists('get_magic_quotes_gpc') && !@get_magic_quotes_gpc())`

### admin/include/functions_upload.inc.php

- [ ] L281 — `@chmod($file_path, 0644);`
- [ ] L477 — `@chmod($format_path, 0644);`
- [ ] L560 — `@exec($exec, $returnarray);`
- [ ] L605 — `@exec($exec, $returnarray);`
- [ ] L654 — `@exec($exec, $returnarray);`
- [ ] L720 — `@exec($ffmpeg.' 2>&1', $FO, $FS);`
- [ ] L730 — `@exec($avconv.' 2>&1', $AO, $AS);`
- [ ] L783 — `@exec($exec, $returnarray);`
- [ ] L838 — `@exec($exec, $returnarray);`
- [ ] L856 — `if (!@mkdir($directory, 0777, $recursive)) {`
- [ ] L863 — `@chmod($directory, 0777);`
- [ ] L993 — `@chmod($upload_dir, 0777);`

### admin/intro.php

- [ ] L253 — `@$activity_last_weeks[$week][$day_nb]['details'][...]`
- [ ] L255 — `@$activity_last_weeks[$week][$day_nb]['date'] = ...`
- [ ] L393 — `@$data_storage[$type]['total']['filesize'] += ...`
- [ ] L396 — `@$data_storage[$type]['details'][strtoupper((string) $ext)] = [...]`
- [ ] L416 — `@$data_storage[$type]['total']['filesize'] += ...`
- [ ] L419 — `@$data_storage[$type]['details'][strtoupper((string) $ext)] = [...]`
- [ ] L430 — `@$data_storage['Cache']['total']['filesize'] = $cacheValue / 1024;`

### admin/maintenance_actions.php

- [ ] L211 — `$lines = @explode("\r\n", $result);`

### admin/maintenance_env.php

- [ ] L198 — `$lines = @explode("\r\n", $result);`

### admin/permalinks.php

- [ ] L54 — `if ($field !== @$_GET[$get_param]) {`

### admin/picture_modify.php

- [ ] L92 — `$post_field = @$_POST[$field];`
- [ ] L230 — `... ? (string) $_POST['name'] : '') : @$row['name'],`
- [ ] L234 — `'DIMENSIONS' => @$row['width'].' * '.@$row['height'],`
- [ ] L238 — `'FILESIZE' => @$row['filesize'].' KB',`

### admin/plugins_installed.php

- [ ] L123 — `'AUTHOR_URL' => @$fs_plugin['author uri'],`

### admin/rating.php

- [ ] L167 — `$template->assign('user_options_selected', [@$_GET['users']]);`

### admin/stats.php

- [ ] L170 — `@$months[$date->format('Y/m/1')][] = $value;`
- [ ] L175 — `@$months[$actual_date->format('Y/m/1')][] = [...]`

### admin/tags.php

- [ ] L139 — `$counter = intval(@$tag_counters[$tag_id_key]);`
- [ ] L141 — `$tag['counter'] = intval(@$tag_counters[$tag_id_key]);`

### admin/themes_installed.php

- [ ] L74 — `'AUTHOR_URL' => @$fs_theme['author uri'],`
- [ ] L75 — `'PARENT' => @$fs_theme['parent'],`
- [ ] L78 — `'ADMIN_URI' => @$fs_theme['admin_uri'],`
- [ ] L142 — `if (@$a['IS_DEFAULT']) {`
- [ ] L145 — `if (@$b['IS_DEFAULT']) {`

### admin/user_list.php

- [ ] L264 — `$content = @file_get_contents($real);`

### i.php

- [ ] L73 — `$mkd = @mkdir($dir, ..., true);`
- [ ] L80 — `file_exists($file) or @file_put_contents($file, 'Not allowed!');`
- [ ] L314 — `$candidate_mtime = @filemtime($candidate_path);`
- [ ] L422 — `$src_mtime = @filemtime($ctx->srcPath);`
- [ ] L428 — `$derivative_mtime = @filemtime($ctx->derivativePath);`
- [ ] L509 — `@set_time_limit(0);`
- [ ] L606 — `@chmod($ctx->derivativePath, 0644);`

### include/category_cats.inc.php

- [ ] L92 — `$row['is_child_date_last'] = @$row['max_date_last'] > @$row['date_last'];`
- [ ] L307 — `@$category['comment'],`

### include/common.inc.php

- [ ] L34 — `if (!function_exists('get_magic_quotes_gpc') or !@get_magic_quotes_gpc())`
- [ ] L90 — `@ini_set('error_reporting', ...)`
- [ ] L92 — `@ini_set('display_errors', true);`
- [ ] L97 — `@ini_set('session.gc_divisor', 100);`
- [ ] L98 — `@ini_set('session.gc_probability', ...);`
- [ ] L292 — `@header('Retry-After: 900');`

### include/derivative_std_params.inc.php

- [ ] L169 — `$arr = @unserialize(...);`

### include/functions.inc.php

- [ ] L151 — `$mkd = @mkdir($dir, ..., ($flags & MKGETDIR_RECURSIVE) ? true : false);`
- [ ] L159 — `file_exists($file) or @file_put_contents($file, 'deny from all');`
- [ ] L163 — `file_exists($file) or @file_put_contents($file, 'Not allowed!');`
- [ ] L1654 — `if (!empty($dirname) && !empty($filename) && !@$options['return']`
- [ ] L1659 — `if (!@$options['return']) {`
- [ ] L1689 — `if (!@$options['no_fallback']) {`
- [ ] L1700 — `$f = @$options['local'] ? ... : ...;`
- [ ] L1712 — `if (!@$options['return']) {`
- [ ] L1716 — `@include(str_replace($selected_language, $forceFallback, $source_file));`
- [ ] L1722 — `@include($source_file);`
- [ ] L1741 — `$content = @file_get_contents($source_file);`
- [ ] L1784 — `@file_put_contents($file, 'Not allowed!');`
- [ ] L1813 — `$key = explode(':', @$key);`
- [ ] L2362 — `... ? @unserialize($result) : false;`
- [ ] L2371 — `@$official_exts[$idxCat][...] = $eid;`
- [ ] L2479 — `@$piwigo_infos['themes_usage'][$theme_used] += $counter;`
- [ ] L2561 — `@$piwigo_infos['updates'][] = [...];`
- [ ] L2609 — `@$apps[$app_name]['counter'] += $activity['counter'];`
- [ ] L2765 — `$file_lines = @file($info_file_path);`

### include/functions_category.inc.php

- [ ] L122 — `$child_date_last = @$row['max_date_last'] > @$row['date_last'];`
- [ ] L743 — `@$cat_ids[$uppercat]++;`
- [ ] L795 — `if (!empty($cat['id_uppercat']) and @$cats[$idx]['count_images'] > 0)`

### include/functions_comment.inc.php

- [ ] L126 — `if (!verify_ephemeral_key(@$key, ...))`

### include/functions_html.inc.php

- [ ] L356 — `$class = isset($bt[$i]['class']) ? (@$bt[$i]['class'].'::') : '';`
- [ ] L370 — `@set_status_header(500);`

### include/functions_mail.inc.php

- [ ] L613 — `$Bcc = get_clean_recipients_list(@$args['Bcc']);`
- [ ] L656 — `if (... and @$args['email_format'] != 'text/plain')`

### include/functions_metadata.inc.php

- [ ] L28 — `if (false == @getimagesize($filename, $imginfo)) {`
- [ ] L124 — `$exif = @exif_read_data($filename) ?: null;`

### include/functions_search.inc.php

- [ ] L902 — `} elseif ('>' == @$str[0]) {`
- [ ] L905 — `} elseif ('<' == @$str[0]) {`
- [ ] L1003 — `} elseif ('>' == @$str[0]) {`
- [ ] L1006 — `} elseif ('<' == @$str[0]) {`

### include/functions_url.inc.php

- [ ] L288 — `$section = @$params['section'];`
- [ ] L521, 555, 558, 561, 564, 567, 570, 574, 576, 584 — 10× `... == @$tokens[$next_token]`

### include/functions_user.inc.php

- [ ] L420 — `@header('Retry-After: 900');`
- [ ] L817 — `$language_header_raw = @$_SERVER['HTTP_ACCEPT_LANGUAGE'];`
- [ ] L1032 — `and is_numeric(@$cookie[0])`
- [ ] L1033 — `and is_numeric(@$cookie[1])`
- [ ] L1034 — `and time() - ... <= @$cookie[1]`
- [ ] L1035 — `and time() >= @$cookie[1]`
- [ ] L2125 — `if (!empty($params['level']) or @$params['level'] === 0)`
- [ ] L2168 — `... or @$params['recent_period'] === 0`
- [ ] L2172 — `... or @$params['expand'] === false`
- [ ] L2177 — `... or @$params['show_nb_comments'] === false`
- [ ] L2182 — `... or @$params['show_nb_hits'] === false`
- [ ] L2187 — `... or @$params['enabled_high'] === false`
- [ ] L2544 — `$result = @pwg_mail(...);`

### include/menubar.inc.php

- [ ] L38 — `if (@$page['section'] == 'search' && ...)`

### include/picture_comment.inc.php

- [ ] L249 — `if ('reject' == @$comment_action) {`

### include/search_filters.inc.php

- [ ] L199 — `@$pre_counters[$threshold]++;`
- [ ] L298 — `@$pre_counters[$threshold]++;`
- [ ] L588 — `@$filesizes[sprintf('%.1f', $fs_val / 1024)]++;`

### include/section_init.inc.php

- [ ] L563 — `@$page['hit_by']['cat_url_name'] !== str2url($page['category']['name'])`
- [ ] L567 — `if ($page['category']['permalink'] !== @$page['hit_by']['cat_permalink'])`

### include/ws_functions/pwg.groups.php

- [ ] L193 — `if (!empty($params['is_default']) or @$params['is_default'] === false)`

### include/ws_functions/pwg.images.php

- [ ] L910 — `@$search['fields']['date_posted']['custom'][] = $date_str;`
- [ ] L920 — `@$search['fields']['date_created']['preset'] = $p_date_created_preset;`
- [ ] L962 — `@$search['fields']['date_created']['custom'][] = $date_str;`
- [ ] L1704 — `if (!$out = @fopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {`
- [ ] L1717 — `if (!$in = @fopen($filesFileTmpName, 'rb')) {`
- [ ] L1721 — `if (!$in = @fopen('php://input', 'rb')) {`
- [ ] L1730 — `@fclose($out);`
- [ ] L1731 — `@fclose($in);`
- [ ] L2001 — `@unlink($output_filepath);`
- [ ] L2219 — `@$unique_filenames_db[ $filename_wo_ext ][] = $row['id'];`

### include/ws_functions/pwg.php

- [ ] L123 — `if (@filemtime($derivative->get_path()) === false) {`
- [ ] L251 — `$infos['msizes'][$size_type] += @$msizes[derivative_to_url($size_type)];`
- [ ] L576 — `$details_raw = @unserialize($row_details_str);`
- [ ] L1041 — `$summary['total_filesize'] += @intval($image_infos[...]['filesize'] ?? 0);`
- [ ] L1153 — `@$sorted_members[$user_name] += 1;`

### include/ws_functions/pwg.users.php

- [ ] L899 — `if (@pwg_mail($user_lost_email, $email_params)) {`

### install.php

- [ ] L84 — `... == @substr(...)`
- [ ] L192 — `@unlink($envTmp);`

### install/upgrade_1.3.1.php

- [ ] L553 — `$config_file_contents = @file_get_contents($config_file);`

### profile.php

- [ ] L388 — `$template_prefixe.'EMAIL' => @$userdata['email'],`

### src/Piwigo/Admin/Image/ImageExtImagick.php

- [ ] L23 — `$script_filename = @$_SERVER['SCRIPT_FILENAME'];`
- [ ] L25 — `@putenv('MAGICK_THREAD_LIMIT=1');`
- [ ] L45 — `@exec($command, $returnarray);`
- [ ] L186 — `@exec($exec, $returnarray);`

### src/Piwigo/Admin/Image/PwgImage.php

- [ ] L277 — `$exif = @exif_read_data($source_filepath);`
- [ ] L387 — `@exec(... .' -version', $returnarray);`

### src/Piwigo/Admin/Languages.php

- [ ] L176 — `@uasort($this->fs_languages, name_compare(...));`
- [ ] L211 — `... and $pem_versions = @unserialize($result)`
- [ ] L264 — `$pem_languages = @unserialize($result);`
- [ ] L278 — `@uasort($this->server_languages, function (...) ...)`
- [ ] L301 — `$handle = @fopen($archive, 'wb');`
- [ ] L377 — `@unlink($path);`
- [ ] L403 — `@unlink($archive);`

### src/Piwigo/Admin/Plugins.php

- [ ] L377 — `... and $pem_versions = @unserialize($result)`
- [ ] L459 — `$pem_plugins = @unserialize($result);`
- [ ] L508 — `$pem_plugins = @unserialize($result);`
- [ ] L585 — `$handle = @fopen($archive, 'wb');`
- [ ] L654 — `@unlink($path);`
- [ ] L680 — `@unlink($archive);`

### src/Piwigo/Admin/Themes.php

- [ ] L434 — `... and $pem_versions = @unserialize($result)`
- [ ] L487 — `$pem_themes = @unserialize($result);`
- [ ] L540 — `$handle = @fopen($archive, 'wb');`
- [ ] L612 — `@unlink($path);`
- [ ] L638 — `@unlink($archive);`

### src/Piwigo/Admin/Updates.php

- [ ] L54 — `and @fetchRemote(PHPWG_URL.'/download/all_versions.php?...', $result)`
- [ ] L55 — `$all_versions = @explode("\n", $result);`
- [ ] L95 — `if (@fetchRemote($url, $result)) {`
- [ ] L259 — `... and $pem_versions = @unserialize($result)`
- [ ] L313 — `$pem_exts = @unserialize($result);`
- [ ] L467 — `@unlink($path);`
- [ ] L510 — `@mkgetdir($path);`
- [ ] L514 — `$zip = @fopen($filename, 'w');`
- [ ] L518 — `if (@fetchRemote(PHPWG_URL.'/download/dlcounter.php?...', $result)`
- [ ] L519 — `and $input = @unserialize($result))`
- [ ] L528 — `@fwrite($zip, base64_decode(...));`
- [ ] L536 — `@fclose($zip);`
- [ ] L539 — `if (@filesize($filename)) {`
- [ ] L560 — `if (@chmod(PHPWG_ROOT_PATH.$extractFilename, 0777)`

### src/Piwigo/Auth/PwgBase32.php

- [ ] L98 — `(string) @self::$flippedMap[@$input[$i + $j]]`

### src/Piwigo/Cache/PersistentFileCache.php

- [ ] L21 — `$fileContent = @file_get_contents($this->dir.$key.'.cache');`
- [ ] L49 — `if (false === @file_put_contents($this->dir.$key.'.cache', $serialized))`
- [ ] L68 — `if ($all || @filemtime($file) < $limit)`
- [ ] L69 — `@unlink($file);`

### src/Piwigo/Core/InstallSentinel.php

- [ ] L39 — `@mkdir($dir, 0o755, true);`
- [ ] L41 — `@touch($path);`
- [ ] L49 — `@unlink($path);`

### src/Piwigo/Core/LanguageStack.php

- [ ] L155 — `@include($path);`

### src/Piwigo/Core/Logger.php

- [ ] L302 — `if (@filemtime($file) < $limit)`
- [ ] L303 — `@unlink($file);`

### src/Piwigo/Image/DerivativeImage.php

- [ ] L192 — `$mtime = @filemtime(PHPWG_ROOT_PATH.$rel_path);`

### src/Piwigo/Image/ImageStdParams.php

- [ ] L121 — `$arr = @unserialize(...);`

### src/Piwigo/Image/SrcImage.php

- [ ] L41 — `$infos['file_ext'] = @strtolower(get_extension($file));`
- [ ] L54 — `if (($size = @getimagesize(PHPWG_ROOT_PATH.$this->rel_path)) === false)`
- [ ] L132 — `if (is_readable($path) && ($size = @getimagesize($path)) !== false)`

### src/Piwigo/Plugins/PiwigoOpenstreetmap/Config.php

- [ ] L56 — `$raw = @unserialize($raw);`

### src/Piwigo/Plugins/PiwigoVideojs/Config.php

- [ ] L60 — `$raw = @unserialize($raw);`

### src/Piwigo/Search/QDateRangeScope.php

- [ ] L22 — `} elseif ('>' == @$str[0]) {`
- [ ] L25 — `} elseif ('<' == @$str[0]) {`

### src/Piwigo/Search/QNumericRangeScope.php

- [ ] L23 — `} elseif ('>' == @$str[0]) {`
- [ ] L26 — `} elseif ('<' == @$str[0]) {`

### src/Piwigo/Template/FileCombiner.php

- [ ] L121 — `@chmod(PHPWG_ROOT_PATH.$file, 0644);`

### src/Piwigo/Ws/PwgServer.php

- [ ] L62 — `@header('Content-Type: text/plain');`
- [ ] L102 — `@header('Content-Type: '.$contentType.'; charset='.get_pwg_charset());`

### tests/Unit/Cache/PersistentFileCacheTest.php

- [ ] L45 — `@rmdir($this->cacheDir);`
- [ ] L46 — `@rmdir($this->tmpRoot);`

### tests/Unit/Config/ConfigLoaderTest.php

- [ ] L43 — `@rmdir($this->tmpDir);`

### tests/Unit/Core/InstallSentinelTest.php

- [ ] L22 — `@mkdir(dirname($this->stampPath), 0o755, true);`

### upgrade.php

- [ ] L22 — `@ini_set('opcache.enable', 0);`
- [ ] L145 — `... == @substr($httpAccLang, 0, 2)`
