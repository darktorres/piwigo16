{combine_script id='doubleSlider' load='footer' path='admin/themes/default/js/doubleSlider.js'}

{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}
{combine_css path="themes/default/css/search.css" order=-100}
{combine_css path="themes/default/css/{$themeconf.colorscheme}-search.css" order=-100}
{combine_css path="themes/default/vendor/fontello/css/gallery-icon.css" order=-10}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_script id='mcs' load='async' path='themes/default/js/mcs.js'}
