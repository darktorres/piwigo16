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

// Live mirrors of server-side checks already run on submit (register.php's
// own password-match/mail-format checks, password.php's/profile's own
// password-match check) -- the server remains authoritative either way.
// Each is gated on both its field(s) AND its own error span existing, so
// the same field id shared across pages (e.g. mail_address on both
// register.latte and profile_content.latte) only binds on the one page
// that actually has the matching inline error span.
function pwg_checkPasswordMatch(pass1Id, pass2Id, errorId)
{
	var pass1 = document.getElementById(pass1Id);
	var pass2 = document.getElementById(pass2Id);
	var error = document.getElementById(errorId);
	if (!pass1 || !pass2 || !error)
	{
		return;
	}

	function check()
	{
		if (pass2.value !== '' && pass1.value !== pass2.value)
		{
			error.textContent = pwg_getPageString('The passwords do not match');
		}
		else
		{
			error.textContent = '';
		}
	}

	pwgAddEventListener(pass1, 'blur', check);
	pwgAddEventListener(pass1, 'keyup', check);
	pwgAddEventListener(pass2, 'blur', check);
	pwgAddEventListener(pass2, 'keyup', check);
}

function pwg_checkEmailFormat(fieldId, errorId)
{
	var field = document.getElementById(fieldId);
	var error = document.getElementById(errorId);
	if (!field || !error)
	{
		return;
	}

	function check()
	{
		if (field.value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value))
		{
			error.textContent = pwg_getPageString('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
		}
		else
		{
			error.textContent = '';
		}
	}

	pwgAddEventListener(field, 'blur', check);
}

// register.latte's own password/password_conf.
pwg_checkPasswordMatch('password', 'password_conf', 'password_conf-error');
// register.latte's own mail_address.
pwg_checkEmailFormat('mail_address', 'mail_address-error');
// password.latte's and profile_content.latte's own shared
// use_new_pwd/passwordConf ids -- only one of the two pages is ever
// rendered per request, so this single binding covers both.
pwg_checkPasswordMatch('use_new_pwd', 'passwordConf', 'passwordConf-error');