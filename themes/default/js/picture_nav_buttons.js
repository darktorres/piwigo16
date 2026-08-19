document.onkeydown = function(e){
	e = e || window.event;
	if (e.altKey) return true;
	var target = e.target || e.srcElement;
	if (target && target.type) return true; // an input editable element
	var keyCode = e.keyCode || e.which, docElem = document.documentElement, url;
	switch (keyCode) {
		case 63235: case 39:
			if (e.ctrlKey || docElem.scrollLeft === docElem.scrollWidth - docElem.clientWidth) url = pwg_getPageData('nav_next_url');
			break;
		case 63234: case 37:
			if (e.ctrlKey || docElem.scrollLeft === 0) url = pwg_getPageData('nav_previous_url');
			break;
		case 36:
			// Home
			if (e.ctrlKey) url = pwg_getPageData('nav_first_url');
			break;
		case 35:
			// End
			if (e.ctrlKey) url = pwg_getPageData('nav_last_url');
			break;
		case 38:
			// Up
			if (e.ctrlKey) url = pwg_getPageData('nav_up_url');
			break;
		case 32:
			// Pause / Play
			url = pwg_getPageData('nav_slideshow_start_url') || pwg_getPageData('nav_slideshow_stop_url');
			break;
	}
	if (url) { window.location = url.replace("&amp;", "&"); return false; }
	return true;
};
