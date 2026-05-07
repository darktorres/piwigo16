{if $load_css} 
	<!--[if lt IE 7]>
		<link rel="stylesheet" type="text/css" href="{$ROOT_URL}themes/_base/fix-ie5-ie6.css">
	<![endif]-->
	<!--[if IE 7]>
		<link rel="stylesheet" type="text/css" href="{$ROOT_URL}themes/_base/fix-ie7.css">
	<![endif]-->
	{combine_css path="themes/_base/print.css" order=-10}
{/if}
