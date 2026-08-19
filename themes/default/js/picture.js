function changeImgSrc(url, typeSave, typeMap)
{
	var theImg = document.getElementById("theMainImage");
	if (theImg)
	{
		theImg.removeAttribute("width");
		theImg.removeAttribute("height");
		theImg.src = url;
		theImg.useMap = "#map" + typeMap;
	}
	jQuery('#derivativeSwitchBox .switchCheck').css('visibility', 'hidden');
	jQuery('#derivativeChecked' + typeMap).css('visibility', 'visible');
	document.cookie = 'picture_deriv=' + typeSave + ';path=' + pwg_getPageData('cookie_path');
}

var derivativeSwitchBox = document.getElementById('derivativeSwitchBox');
if (derivativeSwitchBox)
{
	derivativeSwitchBox.addEventListener('click', function(e) {
		var link = e.target.closest('[data-derivative-url]');
		if (!link)
		{
			return;
		}
		e.preventDefault();
		changeImgSrc(link.dataset.derivativeUrl, link.dataset.derivativeTypeSave, link.dataset.derivativeTypeMap);
	});
}
(window.SwitchBox = window.SwitchBox || []).push("#derivativeSwitchLink", "#derivativeSwitchBox");

var originalLink = document.getElementById('originalLink');
if (originalLink)
{
	originalLink.addEventListener('click', function(e) {
		e.preventDefault();
		phpWGOpenWindow(originalLink.dataset.originalUrl, 'xxx', 'scrollbars=yes,toolbar=no,status=no,resizable=yes');
	});
}

jQuery().ready(function() {
	if (document.getElementById('downloadSwitchBox'))
	{
		jQuery("#downloadSwitchLink").removeAttr("href");
		(window.SwitchBox = window.SwitchBox || []).push("#downloadSwitchLink", "#downloadSwitchBox");
	}
});

function addToCadie(aElement, id)
{
	if (aElement.disabled) return;
	aElement.disabled = true;
	$.ajax({
		url: pwg_getPageData('root_url') + "api/v1/session/caddie",
		method: "POST",
		contentType: "application/json",
		data: JSON.stringify({ imageIds: [id] }),
		headers: {'X-CSRF-Token': pwg_getPageData('csrf_token')},
		error: function(jqXHR) { alert(jqXHR.status + " " + jqXHR.statusText); document.location = aElement.href; },
		success: function(_result) { aElement.disabled = false; }
	});
}

var caddieLink = document.getElementById('caddieLink');
if (caddieLink)
{
	caddieLink.addEventListener('click', function(e) {
		e.preventDefault();
		addToCadie(caddieLink, pwg_getPageData('image_id'));
	});
}

var _pwgRatingAutoQueue = _pwgRatingAutoQueue || [];
_pwgRatingAutoQueue.push({
	rootUrl: pwg_getPageData('root_url'),
	image_id: pwg_getPageData('image_id'),
	onSuccess: function(rating) {
		var e = document.getElementById("updateRate");
		if (e) e.innerHTML = pwg_getPageString('Update your rating');
		e = document.getElementById("ratingScore");
		if (e) e.innerHTML = rating.score;
		e = document.getElementById("ratingCount");
		if (e) {
			if (rating.count === 1) {
				e.innerHTML = ('(' + pwg_getPageString('%d rate') + ')').replace("%d", rating.count);
			} else {
				e.innerHTML = ('(' + pwg_getPageString('%d rates') + ')').replace("%d", rating.count);
			}
		}
	}
});
