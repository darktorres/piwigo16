export {};

$(document).ready(function () {
  $("a.externalLink").click(function () {
    window.open($(this).attr("href"));
    return false;
  });

  $("#admin_mail").keyup(function () {
    $(".adminEmail").text(String($(this).val()));
  });

  let dbCheckXhr: any = null;
  let dbCheckTimer: any = null;

  function dbCheckReady() {
    const host = String($("#dbhost").val()).trim();
    const user = String($("#dbuser").val()).trim();
    const name = String($("#dbname").val()).trim();
    const port = String($("#dbport").val()).trim();
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

  function showDbCheckStatus(cssClass: string, text: string) {
    $("#db-check-row").removeClass("install-hidden-row");
    $("#db-check-status")
      .removeClass("db-check-pending db-check-success db-check-error")
      .addClass(cssClass)
      .text(text);
  }

  function hideDbCheckStatus() {
    $("#db-check-row").addClass("install-hidden-row");
    $("#db-check-status").text("");
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
    hasExistingInstall: any,
    overwriteToken: any,
  ) {
    const row = $("#overwrite-confirm-row");
    if (hasExistingInstall === true) {
      row.removeClass("install-hidden-row");
      if (overwriteToken) {
        $("#overwrite_token").val(overwriteToken);
      }
    } else {
      row.addClass("install-hidden-row");
      $("#confirm_overwrite").prop("checked", false);
    }
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

    dbCheckXhr = $.ajax({
      url: "install.php?ajax=check-db",
      method: "POST",
      dataType: "json",
      data: {
        dbhost: $("#dbhost").val(),
        dbuser: $("#dbuser").val(),
        dbpasswd: $("#dbpasswd").val(),
        dbname: $("#dbname").val(),
        dbdriver: $("#dbdriver").val(),
        dbport: $("#dbport").val(),
      },
      success: function (data: any) {
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
          toggleOverwriteWarning(data.hasExistingInstall, data.overwriteToken);
        } else {
          showDbCheckStatus("db-check-error", (data.errors || []).join(" "));
          toggleOverwriteWarning(false, null);
        }
      },
      error: function (jqXHR: any, textStatus: any) {
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

  $("#dbhost, #dbuser, #dbpasswd, #dbname").on("blur", scheduleDbCheck);
  $("#dbdriver, #dbport").on("change", scheduleDbCheck);

  // Mirrors analyzeForm()'s own preg_match('/[\'"]/', $webmaster) --
  // the "empty login" branch isn't mirrored, the field's own required
  // attribute already blocks that natively.
  function checkWebmasterLogin() {
    const value = String($("#admin_name").val())
      .trim()
      .replace(/\s{2,}/g, " ");
    const error = $("#admin_name-error");
    if (value !== "" && /['"]/.test(value)) {
      error.text(
        pwg_getPageString("webmaster login can't contain characters ' or \""),
      );
    } else {
      error.text("");
    }
  }

  // Mirrors analyzeForm()'s own $this->adminPass1 !== $this->adminPass2.
  function checkPasswordMatch() {
    const pass1 = $("#admin_pass1").val();
    const pass2 = $("#admin_pass2").val();
    const error = $("#admin_pass2-error");
    if (pass2 !== "" && pass1 !== pass2) {
      error.text(pwg_getPageString("please enter your password again"));
    } else {
      error.text("");
    }
  }

  // A deliberate approximation of PHP's FILTER_VALIDATE_EMAIL, not a
  // byte-for-byte mirror (not practically replicable in JS) -- the
  // server remains authoritative on submit.
  function checkAdminEmailFormat() {
    const value = String($("#admin_mail").val()).trim();
    const error = $("#admin_mail-error");
    if (value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      error.text(
        pwg_getPageString(
          "mail address must be like xxx@yyy.eee (example : jack@altern.org)",
        ),
      );
    } else {
      error.text("");
    }
  }

  $("#admin_name").on("blur", checkWebmasterLogin);
  $("#admin_pass1, #admin_pass2").on("blur keyup", checkPasswordMatch);
  $("#admin_mail").on("blur", checkAdminEmailFormat);

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
  $("#language").on("change", function (this: HTMLSelectElement) {
    const form = this.form!;
    form.action = "install.php?language=" + encodeURIComponent(this.value);
    form.submit();
  });
});

jQuery().ready(function () {
  jQuery(".cluetip").cluetip({
    width: 300,
    splitTitle: "|",
    positionBy: "bottomTop",
  });
});
