{combine_css path="themes/default/css/redirect.css"}
<div class="redirect-message">
	{$REDIRECT_MSG}
</div>

<p class="redirect-fallback">
	<a href="{$page_refresh.U_REFRESH}">
		{'Click here if your browser does not automatically forward you'|@translate}
	</a>
</p>
