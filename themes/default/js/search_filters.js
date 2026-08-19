var global_params_json = pwg_getPageData('global_params_json');
if (typeof global_params_json !== "undefined") {
	var global_params = JSON.parse(global_params_json);
}

var fullname_of_cat_json = pwg_getPageData('fullname_of_cat_json');
if (typeof fullname_of_cat_json !== "undefined") {
	var fullname_of_cat = JSON.parse(fullname_of_cat_json);
}

var search_id_from_page = pwg_getPageData('search_id');
if (typeof search_id_from_page !== "undefined") {
	var search_id = search_id_from_page;
}

var user_rank = pwg_getPageData('user_rank');

var str_word_widget_label = pwg_getPageString('Search for words');
var str_tags_widget_label = pwg_getPageString('Tag');
var str_album_widget_label = pwg_getPageString('Album');
var str_author_widget_label = pwg_getPageString('Author');
var str_added_by_widget_label = pwg_getPageString('Added by');
var str_filetypes_widget_label = pwg_getPageString('File type');

var str_rating_widget_label = pwg_getPageString('Rating');
var str_no_rating = pwg_getPageString('no rate');
var str_between_rating = pwg_getPageString('between %d and %d');
var str_filesize_widget_label = pwg_getPageString('Filesize');
var str_width_widget_label = pwg_getPageString('Width');
var str_height_widget_label = pwg_getPageString('Height');
var str_ratio_widget_label = pwg_getPageString('Ratio');
var str_ratios_label = [];
str_ratios_label['Portrait'] = pwg_getPageString('Portrait');
str_ratios_label['square'] = pwg_getPageString('square');
str_ratios_label['Landscape'] = pwg_getPageString('Landscape');
str_ratios_label['Panorama'] = pwg_getPageString('Panorama');
var str_expert_widget_label = pwg_getPageString('Expert mode');

var str_empty_search_top_alt = pwg_getPageString('Fill in the filters to start a search');
var str_empty_search_bot_alt = pwg_getPageString('Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.');
const str_search_in_ab = pwg_getPageString('Search in albums');

const prefix_icon = 'gallery-icon-';

// <!-- sliders config -->
var sliders = {};

var filesize = pwg_getPageData('filesize');
if (filesize) {
	sliders.filesizes = {
		values: filesize.list.split(',').map(Number),
		selected: {
			min: Number(filesize.selected.min),
			max: Number(filesize.selected.max),
		},
		text: pwg_getPageString('between %s and %s MB'),
	};
}

var height = pwg_getPageData('height');
if (height) {
	sliders.heights = {
		values: height.list.split(',').map(Number),
		selected: {
			min: Number(height.selected.min),
			max: Number(height.selected.max),
		},
		text: pwg_getPageString('between %d and %d pixels'),
	};
}

var width = pwg_getPageData('width');
if (width) {
	sliders.widths = {
		values: width.list.split(',').map(Number),
		selected: {
			min: Number(width.selected.min),
			max: Number(width.selected.max),
		},
		text: pwg_getPageString('between %d and %d pixels'),
	};
}

var show_filter_ratings_value = pwg_getPageData('show_filter_ratings');
var show_filter_ratings = (typeof show_filter_ratings_value === "undefined") ? false : show_filter_ratings_value;
