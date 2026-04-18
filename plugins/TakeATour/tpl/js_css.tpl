{footer_script}<script type="module" src="{$ROOT_URL}admin/themes/default/js/dist/{$vite_tat_tour}"></script>{/footer_script}
{combine_css path="node_modules/bootstrap-tour/build/css/bootstrap-tour-standalone.css"}
{if $ADMIN_THEME=='clear'}{combine_css path="plugins/TakeATour/css/clear.css"}{/if}
{if $ADMIN_THEME=='roma'}{combine_css path="plugins/TakeATour/css/roma.css"}{/if}