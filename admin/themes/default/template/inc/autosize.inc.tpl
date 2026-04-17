{footer_script}<script>
	document.addEventListener('DOMContentLoaded', function() {
		document.querySelectorAll('textarea').forEach(function(el) {
			el.style.overflowY = 'hidden';
			el.addEventListener('input', function() {
				el.style.height = 'auto';
				el.style.height = el.scrollHeight + 'px';
			});
			// Set initial height
			el.style.height = el.scrollHeight + 'px';
		});
	});
</script>{/footer_script}
