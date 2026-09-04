// Real module now (docs/PLAN.md P48 -- was a non-module ambient-global
// declarer pre-P48, see git history for the pre-P48 shape). Only
// `phpWGOpenWindow` (picture.ts's own real consumer) and
// `pwgAddEventListener` (rating.ts's own real consumer) convert to real
// exports -- `pwg_tryFocus` stays a permanent `window.X`: not a real
// `.ts` consumer, but a real 4th category-2-like mechanism, confirmed
// directly -- IdentificationView/RegisterView/PasswordView each call it
// via `AssetContribution::inlineScript("pwg_tryFocus('...');")`, a
// literal `<script>` tag embedded raw in the page's own HTML, which
// (like an `onclick=` attribute) executes as a classic, non-module
// script with no `import` available. `popuphelp` also stays permanent
// window.X, conservatively -- no real caller found anywhere in this
// codebase today (checked every `.ts` file, every `.latte` template's
// `onclick=`/`href="javascript:"`, and every `AssetContribution::
// inlineScript()` call site), but `AdminPopuphelpController`'s own
// docblock explicitly describes `popuphelp.php` as reached from this
// exact function, so treating it as safely-unused dead code and
// converting it would risk silently breaking a real caller this
// investigation simply didn't find (Design §1's own "don't assume the
// taxonomy is exhaustive" caution).
import { pwg_getPageString } from "./pageData";

export function phpWGOpenWindow(
  theURL: string,
  winName: string,
  features: string,
): void {
  const img = new Image();
  img.src = theURL;
  let width: number, height: number;
  const imgWasComplete = img.complete;
  if (imgWasComplete) {
    width = img.width + 40;
    height = img.height + 40;
  } else {
    width = 640;
    height = 480;
  }
  const newWin = window.open(
    theURL,
    winName,
    features +
      ",left=2,top=1,width=" +
      String(width) +
      ",height=" +
      String(height),
  )!;
  if (!imgWasComplete) {
    img.onload = function () {
      newWin.resizeTo(img.width + 50, img.height + 100);
    };
  }
}

function popuphelp(url: string): void {
  window.open(
    url,
    "dc_popup",
    "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
  );
}

export function pwgAddEventListener(
  elem: EventTarget,
  evt: string,
  fn: EventListenerOrEventListenerObject,
): void {
  elem.addEventListener(evt, fn, false);
}

function pwg_tryFocus(id: string): void {
  const el = document.getElementById(id);
  if (el) {
    el.focus();
  }
}

document.addEventListener("click", function (e) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an HTMLElement (or null), never a bare EventTarget with no Element interface.
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
  const pass1 = document.querySelector<HTMLInputElement>("#" + pass1Id);
  const pass2 = document.querySelector<HTMLInputElement>("#" + pass2Id);
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
  const field = document.querySelector<HTMLInputElement>("#" + fieldId);
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
// pageData.ts's own copy of this comment for the full explanation).
// `pwg_checkPasswordMatch`/`pwg_checkEmailFormat` don't need this: both
// are only ever called from within this same file, immediately above.
// `phpWGOpenWindow`/`pwgAddEventListener` no longer need this either
// (docs/PLAN.md P48) -- real exports now, imported directly by their
// own real consumers.
window.popuphelp = popuphelp;
window.pwg_tryFocus = pwg_tryFocus;
