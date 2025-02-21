{combine_script id='jquery.bootstrap-tour' load='header' require='jquery' path='node_modules/bootstrap-tour/build/js/bootstrap-tour-standalone.js'}
{combine_css path="node_modules/bootstrap-tour/build/css/bootstrap-tour-standalone.css"}
{if $ADMIN_THEME=='clear'}{combine_css path="plugins/TakeATour/css/clear.css"}{/if}
{if $ADMIN_THEME=='roma'}{combine_css path="plugins/TakeATour/css/roma.css"}{/if}