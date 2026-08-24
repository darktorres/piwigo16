function phpWGOpenWindow(
  theURL: string,
  winName: string,
  features: string,
): void {
  const img = new Image();
  img.src = theURL;
  let width: number, height: number;
  if (img.complete) {
    width = img.width + 40;
    height = img.height + 40;
  } else {
    width = 640;
    height = 480;
    img.onload = function () {
      newWin.resizeTo(img.width + 50, img.height + 100);
    };
  }
  const newWin = window.open(
    theURL,
    winName,
    features + ",left=2,top=1,width=" + width + ",height=" + height,
  )!;
}

function popuphelp(url: string): void {
  window.open(
    url,
    "dc_popup",
    "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
  );
}

function pwgAddEventListener(
  elem: EventTarget,
  evt: string,
  fn: EventListenerOrEventListenerObject,
): void {
  if (typeof window.addEventListener !== "undefined")
    elem.addEventListener(evt, fn, false);
  else (elem as any).attachEvent("on" + evt, fn);
}

function pwg_tryFocus(id: string): void {
  const el = document.getElementById(id);
  if (el) {
    el.focus();
  }
}

document.addEventListener("click", function (e) {
  const link = (e.target as HTMLElement).closest("[data-confirm]");
  if (link && !confirm(pwg_getPageString("Are you sure?"))) {
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
function pwg_checkPasswordMatch(
  pass1Id: string,
  pass2Id: string,
  errorId: string,
): void {
  const pass1 = document.getElementById(pass1Id) as HTMLInputElement | null;
  const pass2 = document.getElementById(pass2Id) as HTMLInputElement | null;
  const error = document.getElementById(errorId);
  if (!pass1 || !pass2 || !error) {
    return;
  }

  function check() {
    if (pass2!.value !== "" && pass1!.value !== pass2!.value) {
      error!.textContent = pwg_getPageString("The passwords do not match");
    } else {
      error!.textContent = "";
    }
  }

  pwgAddEventListener(pass1, "blur", check);
  pwgAddEventListener(pass1, "keyup", check);
  pwgAddEventListener(pass2, "blur", check);
  pwgAddEventListener(pass2, "keyup", check);
}

function pwg_checkEmailFormat(fieldId: string, errorId: string): void {
  const field = document.getElementById(fieldId) as HTMLInputElement | null;
  const error = document.getElementById(errorId);
  if (!field || !error) {
    return;
  }

  function check() {
    if (
      field!.value !== "" &&
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field!.value)
    ) {
      error!.textContent = pwg_getPageString(
        "mail address must be like xxx@yyy.eee (example : jack@altern.org)",
      );
    } else {
      error!.textContent = "";
    }
  }

  pwgAddEventListener(field, "blur", check);
}

// register.latte's own password/password_conf.
pwg_checkPasswordMatch("password", "password_conf", "password_conf-error");
// register.latte's own mail_address.
pwg_checkEmailFormat("mail_address", "mail_address-error");
// password.latte's and profile_content.latte's own shared
// use_new_pwd/passwordConf ids -- only one of the two pages is ever
// rendered per request, so this single binding covers both.
pwg_checkPasswordMatch("use_new_pwd", "passwordConf", "passwordConf-error");

// Explicit `window.` exposure -- required, not decorative (see
// page-data.ts's own copy of this comment for the full explanation).
// `pwg_checkPasswordMatch`/`pwg_checkEmailFormat` don't need this: both
// are only ever called from within this same file, immediately above.
window.phpWGOpenWindow = phpWGOpenWindow;
window.popuphelp = popuphelp;
window.pwgAddEventListener = pwgAddEventListener;
window.pwg_tryFocus = pwg_tryFocus;
