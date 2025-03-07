{if empty($load_mode)}{$load_mode='footer'}{/if}
{combine_script id='jquery-ui-timepicker-addon' load=$load_mode require='jquery.ui' path="node_modules/jQuery-Timepicker-Addon/dist/jquery-ui-timepicker-addon.js"}

{$require='jquery-ui-timepicker-addon'}
{assign var="datepicker_language" value="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/i18n/jquery.ui.datepicker-`$lang_info.jquery_code`.js"}
{if "PHPWG_ROOT_PATH"|@constant|@cat:$datepicker_language|@file_exists}
    {combine_script id="jquery.ui.datepicker-`$lang_info.jquery_code`" load=$load_mode require='jquery.ui' path=$datepicker_language}
    {$require=$require|cat:",jquery.ui.datepicker-`$lang_info.jquery_code`"}
{/if}

{assign var="timepicker_language" value="node_modules/jQuery-Timepicker-Addon/dist/i18n/jquery-ui-timepicker-`$lang_info.jquery_code`.js"}
{if "PHPWG_ROOT_PATH"|@constant|@cat:$datepicker_language|@file_exists}
    {combine_script id="jquery.ui.timepicker-`$lang_info.jquery_code`" load=$load_mode require='jquery-ui-timepicker-addon' path=$timepicker_language}
    {$require=$require|cat:",jquery.ui.timepicker-`$lang_info.jquery_code`"}
{/if}

{combine_script id='datepicker' load=$load_mode require=$require path='admin/themes/default/js/datepicker.js'}

{combine_css path="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery.ui.theme.css"}
{combine_css path="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery.ui.slider.css"}
{combine_css path="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery.ui.datepicker.css"}
{combine_css path="node_modules/jQuery-Timepicker-Addon/dist/jquery-ui-timepicker-addon.css"}