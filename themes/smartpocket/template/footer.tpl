{if !empty($cats_navbar)}
	{include file='navigation_bar.tpl'|get_extent:'navbar' navbar=$cats_navbar}
{elseif !empty($thumb_navbar)}
	{include file='navigation_bar.tpl'|get_extent:'navbar' navbar=$thumb_navbar}
{elseif !empty($navbar) and !isset($ELEMENT_CONTENT)}
	{include file='navigation_bar.tpl'|get_extent:'navbar'}
{else}
	<div class="pwg_footer">
		<h6>
			{'Powered by'|translate} <a href="{$PHPWG_URL}" class="Piwigo">Piwigo</a>
			{$VERSION}
			{if isset($CONTACT_MAIL)}
				- {'Contact'|translate}
				<a
					href="mailto:{$CONTACT_MAIL}?subject={'A comment on your site'|translate|escape:url}">{'Webmaster'|translate}</a>
			{/if}
			<br>{'View in'|translate} :
			<b>{'Mobile'|translate}</b> | {if isset($TOGGLE_MOBILE_THEME_URL)} <a
				href="{$TOGGLE_MOBILE_THEME_URL}">{'Desktop'|translate}</a> {/if}
		</h6>
	</div>
{/if}
{footer_script}<script>
(function() {
    var menubar = document.getElementById('menubar');
    if (!menubar) return;
    var overlay = document.createElement('div');
    overlay.className = 'sp-overlay';
    document.body.appendChild(overlay);
    function spOpen()  { menubar.classList.add('sp-open');    overlay.classList.add('sp-open'); }
    function spClose() { menubar.classList.remove('sp-open'); overlay.classList.remove('sp-open'); }
    document.querySelectorAll('a[href="#menubar"]').forEach(function(a) {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            a.dataset.rel === 'close' ? spClose() : spOpen();
        });
    });
    overlay.addEventListener('click', spClose);
    document.querySelectorAll('.sp-collapsible').forEach(function(el) {
        var h = el.querySelector('h3');
        if (h) h.addEventListener('click', function() { el.classList.toggle('sp-open'); });
    });
})();
</script>{/footer_script}
{footer_script}<script>
	document.cookie = 'screen_size='+window.innerWidth+'x'+window.innerHeight;{if isset($COOKIE_PATH)}path={$COOKIE_PATH};{/if}
</script>{/footer_script}
{get_combined_scripts load='footer'}
{if isset($footer_elements)}
	{foreach $footer_elements as $v}
		{$v}
	{/foreach}
{/if}
</div><!-- /page -->

</body>

</html>