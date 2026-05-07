{combine_css path="themes/admin/_base/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}
{combine_css path="themes/_base/css/search.css" order=-100}
{combine_css path="themes/_base/css/{$themeconf.colorscheme}-search.css" order=-100}
{combine_css path="themes/_base/vendor/fontello/css/gallery-icon.css" order=-10}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_script id='mcs' load='async' path='themes/_base/js/mcs.js'}
