{combine_script id='jquery.autogrow' load='async' require='jquery' path='https://rawcdn.githack.com/Piwigo/Piwigo/refs/heads/14.x/themes/default/js/plugins/jquery.autogrow-textarea.js'}
{* Auto size and auto grow textarea *}
{footer_script require='jquery.autogrow'}<script>
	jQuery(document).ready(function() {
		jQuery('textarea').css('overflow-y', 'hidden');
		// Auto size and auto grow for all text area
		jQuery('textarea').autogrow();
	});
</script>{/footer_script}