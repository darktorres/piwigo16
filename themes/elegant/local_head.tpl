{footer_script}<script type="application/json" id="elegant-config">{"p_main_menu":"{$elegant.p_main_menu}","p_pict_descr":"{$elegant.p_pict_descr}","p_pict_comment":"{$elegant.p_pict_comment}"}</script>{/footer_script}

{footer_script}<script type="module">
	const _cfg = JSON.parse(document.getElementById('elegant-config').textContent);
	{if $BODY_ID=='thePicturePage'}
	import('./themes/elegant/scripts_pp.js').then(m => m.init(_cfg));
	{else}
	import('./themes/elegant/scripts.js').then(m => m.init(_cfg));
	{/if}
</script>{/footer_script}