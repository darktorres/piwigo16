{if empty($load_mode)}{$load_mode='footer'}{/if}
{combine_script id='flatpickr' load=$load_mode path='node_modules/flatpickr/dist/flatpickr.min.js'}

{assign var="flatpickr_l10n" value="node_modules/flatpickr/dist/l10n/`$lang_info.jquery_code`.js"}
{if "PHPWG_ROOT_PATH"|constant|cat:$flatpickr_l10n|file_exists}
  {combine_script id="flatpickr-l10n-`$lang_info.jquery_code`" load=$load_mode require='flatpickr' path=$flatpickr_l10n}
{/if}

{combine_script id='datepicker' load=$load_mode require='flatpickr' path='admin/themes/default/js/datepicker.js'}

{combine_css path='node_modules/flatpickr/dist/flatpickr.min.css'}
