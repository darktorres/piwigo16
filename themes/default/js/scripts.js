function phpWGOpenWindow(theURL,winName,features)
{
	img = new Image();
	img.src = theURL;
	if (img.complete)
	{
		var width=img.width+40, height=img.height+40;
	}
	else
	{
		var width=640, height=480;
		img.onload = function () { newWin.resizeTo( img.width+50, img.height+100); };
	}
	newWin = window.open(theURL,winName,features+',left=2,top=1,width=' + width + ',height=' + height);
}

function popuphelp(url)
{
	window.open( url, 'dc_popup',
		'alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no'
	);
}

function pwgAddEventListener(elem, evt, fn)
{
	if (window.addEventListener)
		elem.addEventListener(evt, fn, false);
	else
		elem.attachEvent('on'+evt, fn);
}

function pwg_tryFocus(id)
{
	var el = document.getElementById(id);
	if (el)
	{
		el.focus();
	}
}

document.addEventListener('click', function(e) {
	var link = e.target.closest('[data-confirm]');
	if (link && !confirm(pwg_getPageString('Are you sure?')))
	{
		e.preventDefault();
	}
});