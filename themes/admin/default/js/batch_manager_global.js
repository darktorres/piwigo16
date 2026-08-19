/* global derivatives, progress_start, progress, getDerivativeUrls -- themes/admin/default/js/batchManagerGlobal.js, loaded via the same page's own combineScript() call */

var lang = {
	Cancel: pwg_getPageString('Cancel'),
	deleteProgressMessage: pwg_getPageString('Deletion in progress'),
	syncProgressMessage: pwg_getPageString('Synchronization in progress'),
	AreYouSure: pwg_getPageString('Are you sure?'),
	generateMsg: pwg_getPageString('Generate multiple size images')
};

jQuery(document).ready(function() {

	// <!-- TAGS -->
	var tagsCache = new TagsCache({
		serverKey: pwg_getPageData('cache_key_tags'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	tagsCache.selectize(jQuery('[data-selectize=tags]'), { lang: {
		'Add': pwg_getPageString('Create')
	}});

	// <!-- CATEGORIES -->
	var categoriesCache = new CategoriesCache({
		serverKey: pwg_getPageData('cache_key_categories'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	var associated_categories = pwg_getPageData('associated_categories');

	categoriesCache.selectize(jQuery('[data-selectize=categories]'), {
		filter: function(categories, options) {
			if (this.name === 'dissociate') {
				var filtered = jQuery.grep(categories, function(cat) {
					return Boolean(associated_categories[cat.id]);
				});

				if (filtered.length > 0) {
					options.default = filtered[0].id;
				}

				return filtered;
			}
			else {
				return categories;
			}
		}
	});

});

var nb_thumbs_page = pwg_getPageData('nb_thumbs_page');
var nb_thumbs_set = pwg_getPageData('nb_thumbs_set');
var applyOnDetails_pattern = pwg_getPageString('on the %d selected photos');
var all_elements = pwg_getPageData('all_elements') || [];

var selectedMessage_pattern = pwg_getPageString('%d of %d photos selected');
var selectedMessage_none = pwg_getPageString('No photo selected, %d photos in current set');
var selectedMessage_all = pwg_getPageString('All %d photos are selected');
const str_add_alb_associate = pwg_getPageString('Add Album');
const str_select_alb_associate = pwg_getPageString('Select an album');

$(document).ready(function() {
	function checkPermitAction() {
		var nbSelected;
		if ($("input[name=setSelected]").is(':checked')) {
			nbSelected = nb_thumbs_set;
		}
		else {
			nbSelected = $(".thumbnails input[type=checkbox]").filter(':checked').length;
		}

		if (nbSelected === 0) {
			$("#permitAction").hide();
			$("#forbidAction").show();
		}
		else {
			$("#permitAction").show();
			$("#forbidAction").hide();
		}

		$("#applyOnDetails").text(
			sprintf(
				applyOnDetails_pattern,
				nbSelected
			)
		);

		// display the number of currently selected photos in the "Selection" fieldset
		if (nbSelected === 0) {
			$("#selectedMessage").text(
				sprintf(
					selectedMessage_none,
					nb_thumbs_set
				)
			);
		}
		else if (nbSelected === nb_thumbs_set) {
			$("#selectedMessage").text(
				sprintf(
					selectedMessage_all,
					nb_thumbs_set
				)
			);
		}
		else {
			$("#selectedMessage").text(
				sprintf(
					selectedMessage_pattern,
					nbSelected,
					nb_thumbs_set
				)
			);
		}
	}



	$("[id^=action_]").hide();

	$("select[name=selectAction]").change(function () {
		$("[id^=action_]").hide();

		var action = $(this).prop("value");
		// if (action == 'move') {
		//   action = 'associate';
		// }

		$("#action_"+action).show();

		// eslint-disable-next-line eqeqeq -- .val() returns a string; -1 is the real sentinel, loose equality intentional
		if ($(this).val() != -1) {
			$("#applyActionBlock").show();
		}
		else {
			$("#applyActionBlock").hide();
		}
		if ($(this).val() === "delete" || $(this).val() === "delete_derivatives") {
			$("#confirmDel").css("visibility", "visible");
		} else {
			$("#confirmDel").css("visibility", "hidden");
		}
	});

	$(".wrap1 label").click(function (event) {
		$("input[name=setSelected]").prop('checked', false).trigger('change');

		var li = $(this).closest("li");
		var checkbox = $(this).children("input[type=checkbox]");

		checkbox.triggerHandler("shclick",event);

		if ($(checkbox).is(':checked')) {
			$(li).addClass("thumbSelected");
		}
		else {
			$(li).removeClass('thumbSelected');
		}

		checkPermitAction();
	});

	$("#selectAll").click(function () {
		$("input[name=setSelected]").prop('checked', false).trigger('change');
		selectPageThumbnails();
		checkPermitAction();
		return false;
	});

	function selectPageThumbnails() {
		$(".thumbnails label").each(function() {
			var checkbox = $(this).children("input[type=checkbox]");

			$(checkbox).prop('checked', true).trigger("change");
			$(this).closest("li").addClass("thumbSelected");
		});
	}

	$("#selectNone").click(function () {
		$("input[name=setSelected]").prop('checked', false).trigger('change');

		$(".thumbnails label").each(function() {
			var checkbox = $(this).children("input[type=checkbox]");

			if (jQuery(checkbox).is(':checked')) {
				$(checkbox).prop('checked', false).trigger("change");
			}

			$(this).closest("li").removeClass("thumbSelected");
		});
		checkPermitAction();
		return false;
	});

	$("#selectInvert").click(function () {
		$("input[name=setSelected]").prop('checked', false).trigger('change');

		$(".thumbnails label").each(function() {
			var checkbox = $(this).children("input[type=checkbox]");

			$(checkbox).prop('checked', !$(checkbox).is(':checked')).trigger("change");

			if ($(checkbox).is(':checked')) {
				$(this).closest("li").addClass("thumbSelected");
			}
			else {
				$(this).closest("li").removeClass('thumbSelected');
			}
		});
		checkPermitAction();
		return false;
	});

	$("#selectSet").click(function () {
		selectPageThumbnails();
		$("input[name=setSelected]").prop('checked', true).trigger('change');
		checkPermitAction();
		return false;
	});

	$("input[name=setSelected]").change(function() {
		$('input[name=whole_set]').val(this.checked ? all_elements.join(',') : '');
	});

	// if the whole set is selected on page load (after a first action has been applied),
	// trigger a change to make sure input[name=whole_set] is updated
	if ($('input[name="setSelected"]').is(':checked')) {
		$("input[name=setSelected]").trigger('change');
	}

	jQuery("input[name=confirm_deletion]").change(function() {
		jQuery("#confirmDel span.errors").css("visibility", "hidden");
	});

	jQuery('#applyAction').click(function() {
		var action = jQuery('[name="selectAction"]').val();
		if (action === 'delete_derivatives') {
			let d_count = $('#confirmDel input[type=checkbox]').filter(':checked').length
			let e_count = $('input[name="setSelected"]').is(':checked') ? nb_thumbs_set : $('.thumbnails input[type=checkbox]').filter(':checked').length;
			if (!jQuery("#confirmDel input[name=confirm_deletion]").is(':checked')) {
				jQuery("#confirmDel span.errors").css("visibility", "visible");
				return false;
			} else {
				return true;
			}
		}

		if (action !== 'generate_derivatives'
			|| derivatives.finished() )
		{
			return true;
		}

		jQuery('.bulkAction').hide();

		var queuedManager = jQuery.manageAjax.create('queued', {
			queue: true,
			cacheResponse: false,
			maxRequests: 1
		});

		derivatives.elements = [];
		if (jQuery('input[name="setSelected"]').is(':checked'))
			derivatives.elements = all_elements;
		else
			jQuery('.thumbnails input[type=checkbox]').each(function() {
				if (jQuery(this).is(':checked')) {
					derivatives.elements.push(jQuery(this).val());
				}
			});

		jQuery('#applyActionBlock').hide();
		jQuery('select[name="selectAction"]').hide();
		jQuery('.permitActionListButton div').addClass('hidden');
		jQuery('#regenerationMsg').show();

		progress_start();
		progress();
		getDerivativeUrls();
		return false;
	});

	checkPermitAction();

	jQuery("select[name=filter_prefilter]").change(function() {
		jQuery("#empty_caddie").toggle(jQuery(this).val() === "caddie");
		jQuery("#duplicates_options").toggle(jQuery(this).val() === "duplicates");
		jQuery("#delete_orphans").toggle(jQuery(this).val() === "no_album");
		jQuery("#sync_md5sum").toggle(jQuery(this).val() === "no_sync_md5sum");
	});
});
