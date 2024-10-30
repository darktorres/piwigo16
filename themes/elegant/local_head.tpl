{footer_script}
  var p_main_menu = "{$elegant.p_main_menu}", p_pict_descr = "{$elegant.p_pict_descr}", p_pict_comment = "{$elegant.p_pict_comment}";
{/footer_script}
{if $BODY_ID=='thePicturePage'}
	{combine_script id='elegant.scripts_pp' load='footer' require='jquery' path='themes/elegant/scripts_pp.js'}
{else}
	{combine_script id='elegant.scripts' load='footer' require='jquery' path='themes/elegant/scripts.js'}
{/if}
