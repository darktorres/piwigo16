{if empty($load_mode)}{$load_mode='footer'}{/if}
{combine_script id='glightbox' load=$load_mode path='node_modules/glightbox/dist/js/glightbox.min.js'}
{combine_css id='glightbox' path='node_modules/glightbox/dist/css/glightbox.min.css'}