{footer_script}<script type="module">
	window.p_main_menu = "{$elegant.p_main_menu}";
	window.p_pict_descr = "{$elegant.p_pict_descr}";
	window.p_pict_comment = "{$elegant.p_pict_comment}";
	{if $BODY_ID=='thePicturePage'}
	import './themes/elegant/scripts_pp.js';
	{else}
	import './themes/elegant/scripts.js';
	{/if}
</script>{/footer_script}