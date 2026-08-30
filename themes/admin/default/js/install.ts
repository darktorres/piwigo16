import { pwg_getPageString } from "../../../default/js/page-data";
import { ajax, type AjaxThenable } from "../../../default/js/vendor/ajax";
import {
  addClass,
  on,
  ready,
  removeClass,
  setVal,
  text as setText,
} from "../../../default/js/vendor/dom";
export {};

/** Every selector here is an id or an id list; static, as jQuery's were. */
function q(selector: string): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>(selector));
}

/** `$("#x").val()` on a field that always exists on this form. */
function fieldValue(id: string): string {
  const field = document.getElementById(id);

  return field instanceof HTMLInputElement || field instanceof HTMLSelectElement
    ? field.value
    : "";
}

const adminMail = document.getElementById(
  "admin_mail",
) as HTMLInputElement | null;

ready(function () {
  document.querySelectorAll("a.externalLink").forEach((link) => {
    link.addEventListener("click", function (event) {
      window.open(link.getAttribute("href") ?? "");

      // `return false` from a jQuery handler.
      event.preventDefault();
      event.stopPropagation();
    });
  });

  adminMail?.addEventListener("keyup", function () {
    setText(document.querySelectorAll(".adminEmail"), adminMail.value);
  });

  let dbCheckXhr: AjaxThenable | null = null;
  let dbCheckTimer: ReturnType<typeof setTimeout> | null = null;

  function dbCheckReady() {
    const host = fieldValue("dbhost").trim();
    const user = fieldValue("dbuser").trim();
    const name = fieldValue("dbname").trim();
    const port = fieldValue("dbport").trim();
    if (host === "" || user === "" || name === "") {
      return false;
    }
    // Mirrors InstallWizardRequest::fromArrays()'s own dbport regex --
    // a value failing that pattern throws server-side before install.php
    // ever reaches the ajax branch, so it must never be sent.
    if (port !== "" && !/^\d{1,5}$/.test(port)) {
      return false;
    }
    return true;
  }

  function showDbCheckStatus(cssClass: string, message: string) {
    removeClass(q("#db-check-row"), "install-hidden-row");
    const status = q("#db-check-status");
    removeClass(status, "db-check-pending db-check-success db-check-error");
    addClass(status, cssClass);
    setText(status, message);
  }

  function hideDbCheckStatus() {
    addClass(q("#db-check-row"), "install-hidden-row");
    setText(q("#db-check-status"), "");
  }

  // Three explicit states, not a truthy/falsy shortcut over
  // hasExistingInstall -- null and false are not the same thing, a loose
  // check would silently give an operator no live warning about a
  // privilege problem, the one case that most needs it. true shows the
  // warning/checkbox and writes overwriteToken into the hidden field
  // every time a response includes one (not just the first -- a later
  // debounced re-check mints a fresh cookie+token pair, and the hidden
  // field must track whichever is current or the eventual real submit
  // fails the match); null/false hide the checkbox row (the null case's
  // own distinct message is shown via db-check-status instead, see
  // runDbCheck()'s success handler below).
  function toggleOverwriteWarning(
    hasExistingInstall: boolean | null,
    overwriteToken: string | null,
  ) {
    const row = q("#overwrite-confirm-row");
    if (hasExistingInstall === true) {
      removeClass(row, "install-hidden-row");
      if (overwriteToken) {
        setVal(q("#overwrite_token"), overwriteToken);
      }
    } else {
      addClass(row, "install-hidden-row");
      const confirm = document.getElementById(
        "confirm_overwrite",
      ) as HTMLInputElement | null;
      if (confirm !== null) {
        confirm.checked = false;
      }
    }
  }

  // install.php's own ajax=check-db action -- not part of the real REST
  // API (installer-only, no OpenAPI coverage), so hand-typed from this
  // file's own real usage rather than the schema.
  interface DbCheckResponse {
    ok: boolean;
    hasExistingInstall: boolean | null;
    overwriteToken?: string;
    errors?: string[];
  }

  function runDbCheck() {
    if (dbCheckXhr !== null) {
      dbCheckXhr.abort();
    }
    if (!dbCheckReady()) {
      hideDbCheckStatus();
      toggleOverwriteWarning(false, null);
      return;
    }

    showDbCheckStatus(
      "db-check-pending",
      pwg_getPageString("Testing connection..."),
    );

    dbCheckXhr = ajax({
      url: "install.php?ajax=check-db",
      method: "POST",
      dataType: "json",
      data: {
        dbhost: fieldValue("dbhost"),
        dbuser: fieldValue("dbuser"),
        dbpasswd: fieldValue("dbpasswd"),
        dbname: fieldValue("dbname"),
        dbdriver: fieldValue("dbdriver"),
        dbport: fieldValue("dbport"),
      },
      success: function (data: DbCheckResponse) {
        if (data.ok) {
          if (data.hasExistingInstall === null) {
            showDbCheckStatus(
              "db-check-warning",
              pwg_getPageString(
                "Connected to the database, but couldn't verify whether it already contains a Piwigo installation — check the database user's privileges to list tables",
              ),
            );
          } else {
            showDbCheckStatus(
              "db-check-success",
              pwg_getPageString("Connection successful"),
            );
          }
          toggleOverwriteWarning(
            data.hasExistingInstall,
            data.overwriteToken ?? null,
          );
        } else {
          showDbCheckStatus("db-check-error", (data.errors || []).join(" "));
          toggleOverwriteWarning(false, null);
        }
      },
      error: function (jqXHR, textStatus: string) {
        if (textStatus === "abort") {
          return;
        }
        hideDbCheckStatus();
        toggleOverwriteWarning(false, null);
      },
      complete: function () {
        dbCheckXhr = null;
      },
    });
  }

  function scheduleDbCheck() {
    if (dbCheckTimer !== null) {
      clearTimeout(dbCheckTimer);
    }
    dbCheckTimer = setTimeout(runDbCheck, 500);
  }

  on(q("#dbhost, #dbuser, #dbpasswd, #dbname"), "blur", scheduleDbCheck);
  on(q("#dbdriver, #dbport"), "change", scheduleDbCheck);

  // Mirrors analyzeForm()'s own preg_match('/[\'"]/', $webmaster) --
  // the "empty login" branch isn't mirrored, the field's own required
  // attribute already blocks that natively.
  function checkWebmasterLogin() {
    const value = fieldValue("admin_name")
      .trim()
      .replace(/\s{2,}/g, " ");
    const error = q("#admin_name-error");
    if (value !== "" && /['"]/.test(value)) {
      setText(
        error,
        pwg_getPageString("webmaster login can't contain characters ' or \""),
      );
    } else {
      setText(error, "");
    }
  }

  // Mirrors analyzeForm()'s own $this->adminPass1 !== $this->adminPass2.
  function checkPasswordMatch() {
    const pass1 = fieldValue("admin_pass1");
    const pass2 = fieldValue("admin_pass2");
    const error = q("#admin_pass2-error");
    if (pass2 !== "" && pass1 !== pass2) {
      setText(error, pwg_getPageString("please enter your password again"));
    } else {
      setText(error, "");
    }
  }

  // A deliberate approximation of PHP's FILTER_VALIDATE_EMAIL, not a
  // byte-for-byte mirror (not practically replicable in JS) -- the
  // server remains authoritative on submit.
  function checkAdminEmailFormat() {
    const value = fieldValue("admin_mail").trim();
    const error = q("#admin_mail-error");
    if (value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      setText(
        error,
        pwg_getPageString(
          "mail address must be like xxx@yyy.eee (example : jack@altern.org)",
        ),
      );
    } else {
      setText(error, "");
    }
  }

  on(q("#admin_name"), "blur", checkWebmasterLogin);
  on(q("#admin_pass1, #admin_pass2"), "blur keyup", checkPasswordMatch);
  on(q("#admin_mail"), "blur", checkAdminEmailFormat);

  // Real POST resubmit of the whole form on language change, instead of
  // the old plain document.location navigation that discarded every
  // other field the operator had already typed -- boot() already reads
  // every field from $_POST unconditionally (regardless of whether
  // install was clicked), so the redisplayed form comes back sticky.
  // .submit() (not .requestSubmit()) deliberately bypasses the
  // Constraint Validation API -- several fields on this form are
  // required, and this resubmit must never be blocked by them. That
  // also means no "submit" event fires here at all, so this can't be
  // implemented as a submit handler -- confirmed nothing else in this
  // file needs one.
  const language = document.getElementById(
    "language",
  ) as HTMLSelectElement | null;
  language?.addEventListener("change", function () {
    const form = language.form!;
    form.action = "install.php?language=" + encodeURIComponent(language.value);
    form.submit();
  });
});

// The only jQuery left in this file, and the only part of it that is not
// P49-A work: cluetip is a library, and this call goes when that library is
// ported (docs/PLAN.md P49-B group 3). InstallView.php's own registration of
// jQuery and jquery.cluetip (its lines 98/100) goes with it. The `ready()`
// wrapper around it was P49-A and is converted.
ready(function () {
  jQuery(".cluetip").cluetip({
    width: 300,
    splitTitle: "|",
    positionBy: "bottomTop",
  });
});
