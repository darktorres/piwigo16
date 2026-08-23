$(document).ready(function() {
  $("a.externalLink").click(function() {
    window.open($(this).attr("href"));
    return false;
  });

  $("#admin_mail").keyup(function() {
    $(".adminEmail").text($(this).val());
  });

  var dbCheckXhr = null;
  var dbCheckTimer = null;

  function dbCheckReady() {
    var host = $.trim($("#dbhost").val());
    var user = $.trim($("#dbuser").val());
    var name = $.trim($("#dbname").val());
    var port = $.trim($("#dbport").val());
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

  function showDbCheckStatus(cssClass, text) {
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

  function runDbCheck() {
    if (dbCheckXhr !== null) {
      dbCheckXhr.abort();
    }
    if (!dbCheckReady()) {
      hideDbCheckStatus();
      return;
    }

    showDbCheckStatus("db-check-pending", pwg_getPageString("Testing connection..."));

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
        dbport: $("#dbport").val()
      },
      success: function(data) {
        if (data.ok) {
          showDbCheckStatus("db-check-success", pwg_getPageString("Connection successful"));
        } else {
          showDbCheckStatus("db-check-error", (data.errors || []).join(" "));
        }
      },
      error: function(jqXHR, textStatus) {
        if (textStatus === "abort") {
          return;
        }
        hideDbCheckStatus();
      },
      complete: function() {
        dbCheckXhr = null;
      }
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
    var value = $.trim($("#admin_name").val()).replace(/\s{2,}/g, " ");
    var error = $("#admin_name-error");
    if (value !== "" && /['"]/.test(value)) {
      error.text(pwg_getPageString("webmaster login can't contain characters ' or \""));
    } else {
      error.text("");
    }
  }

  // Mirrors analyzeForm()'s own $this->adminPass1 !== $this->adminPass2.
  function checkPasswordMatch() {
    var pass1 = $("#admin_pass1").val();
    var pass2 = $("#admin_pass2").val();
    var error = $("#admin_pass2-error");
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
    var value = $.trim($("#admin_mail").val());
    var error = $("#admin_mail-error");
    if (value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      error.text(pwg_getPageString("mail address must be like xxx@yyy.eee (example : jack@altern.org)"));
    } else {
      error.text("");
    }
  }

  $("#admin_name").on("blur", checkWebmasterLogin);
  $("#admin_pass1, #admin_pass2").on("blur keyup", checkPasswordMatch);
  $("#admin_mail").on("blur", checkAdminEmailFormat);
});

jQuery().ready(function(){
  jQuery('.cluetip').cluetip({
    width: 300,
    splitTitle: '|',
    positionBy: 'bottomTop'
  });
});
