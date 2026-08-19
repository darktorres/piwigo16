jQuery(document).ready(function() {

	jQuery("input[name=who]").change(function () {
		checkWhoOptions();
	});

	checkWhoOptions();

	function checkWhoOptions() {
		var option = jQuery("input[name=who]:checked").val();
		jQuery(".who_option").hide();
		jQuery(".who_" + option).show();
	}

	jQuery(".who_option select").selectize({
		plugins: ['remove_button']
	});

	jQuery("form#categoryNotify").submit(function(e) {
		var who_selected = false;
		var who_option = jQuery("input[name=who]:checked").val();

		if (jQuery(".who_" + who_option + " select").length > 0) {
			if (jQuery(".who_" + who_option + " select option:selected").length > 0) {
				who_selected = true;
			}
		}

		if (!who_selected) {
			jQuery(".actionButtons .errors").show();
			e.preventDefault();
		}
		else {
			jQuery(".actionButtons .errors").hide();
			console.log("form can be submited");
		}
	});
});
