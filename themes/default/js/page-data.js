var pwg_pageDataPayload = null;

function pwg_getPageDataPayload()
{
	if (pwg_pageDataPayload === null)
	{
		var el = document.getElementById('page-data');
		pwg_pageDataPayload = el ? JSON.parse(el.textContent) : { data: {}, strings: {} };
	}
	return pwg_pageDataPayload;
}

function pwg_getPageData(key)
{
	return pwg_getPageDataPayload().data[key];
}

function pwg_getPageString(key)
{
	return pwg_getPageDataPayload().strings[key];
}
