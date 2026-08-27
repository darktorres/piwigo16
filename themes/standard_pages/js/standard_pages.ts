import { pwg_getPageString } from "../../default/js/page-data";
export {};

const modeCookie = getCookie("mode");
if ("" != modeCookie) {
  toggle_mode(modeCookie);
} else {
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  console.log(prefersDark);
  toggle_mode(prefersDark ? "dark" : "light");
}

window
  .matchMedia("(prefers-color-scheme: dark)")
  .addEventListener("change", (event) => {
    const newMode = event.matches ? "dark" : "light";
    toggle_mode(newMode);
  });

jQuery(document).ready(function () {
  //Override empty input message
  jQuery("form").on("submit", function (e) {
    let isValid = true;

    jQuery(".column-flex").each(function (i) {
      // Because we overid the default browser error message
      // we need to distinguish which fields are now required
      // To do this we use data-required="true" on the input
      const input = $(this).find("input");
      if ($(input).data("required") == true) {
        const input = jQuery(this).find("input");
        const errorMessage = jQuery(this).find(".error-message");
        if (!String(input.val() ?? "").trim()) {
          e.preventDefault();
          (input[0] as HTMLInputElement).setCustomValidity(""); // Override browser tooltip (empty space hides it)
          errorMessage.show();
          isValid = false;
        } else {
          (input[0] as HTMLInputElement).setCustomValidity("");
          errorMessage.hide();
        }
      }
    });

    return isValid;
  });

  // Hide error message and reset validation on input
  jQuery(".column-flex input").on("input", function () {
    const errorMessage = jQuery(this)
      .closest(".column-flex")
      .find(".error-message");
    (jQuery(this)[0] as HTMLInputElement).setCustomValidity(""); // Reset browser tooltip
    errorMessage.hide();
  });

  // Hide error message when user starts typing
  jQuery(".column-flex input").on("input", function () {
    jQuery(this).closest(".column-flex").find(".error-message").hide();
  });
});

function toggle_mode(mode: string) {
  setCookie("mode", mode, 30);
  const logo = document.getElementById(
    "piwigo-logo",
  ) as HTMLImageElement | null;
  if ("dark" === mode) {
    //Dark mode
    jQuery("#toggle_mode_light").hide();
    jQuery("#toggle_mode_dark").show();
    jQuery("#mode").addClass("dark");
    jQuery("#mode").removeClass("light");
    if (logo) {
      logo.src = logo.dataset.logoDark!;
    }
  } else {
    //Light mode
    jQuery("#toggle_mode_dark").hide();
    jQuery("#toggle_mode_light").show();
    jQuery("#mode").addClass("light");
    jQuery("#mode").removeClass("dark");
    if (logo) {
      logo.src = logo.dataset.logoLight!;
    }
  }
}

function setCookie(cname: string, cvalue: string, exdays: number) {
  const d = new Date();
  d.setTime(d.getTime() + exdays * 24 * 60 * 60 * 1000);
  const expires = "expires=" + d.toUTCString();
  document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
  if (cname == "lang") {
    location.reload();
  }
}

function getCookie(cname: string) {
  const name = cname + "=";
  const decodedCookie = decodeURIComponent(document.cookie);
  const ca = decodedCookie.split(";");
  for (let i = 0; i < ca.length; i++) {
    let c = ca[i]!;
    while (c.charAt(0) == " ") {
      c = c.substring(1);
    }
    if (c.indexOf(name) == 0) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}

jQuery(".togglePassword").click(function (e) {
  const toggle = jQuery(e.target);
  const input = jQuery(toggle).siblings("input")[0] as HTMLInputElement;
  if (input.type === "password") {
    input.type = "text";
    jQuery(toggle).css("color", "#ff7700");
  } else {
    input.type = "password";
    jQuery(toggle).css("color", "#898989");
  }
});

jQuery("#other-languages a").click(function (e) {
  const clickedUrl = new URL(jQuery(e.target).attr("href")!);
  const selectedLang = clickedUrl.searchParams.get("lang");

  if (selectedLang) {
    setCookie("lang", selectedLang, 1);
  }
});

jQuery("#toggle_mode_light").click(function () {
  toggle_mode("dark");
});

jQuery("#toggle_mode_dark").click(function () {
  toggle_mode("light");
});

jQuery("#other-languages").on("click", "[data-lang-code]", function () {
  setCookie("lang", jQuery(this).data("langCode") as string, 30);
});

// Live mirrors of server-side checks already run on submit
// (RegisterController's/PasswordController's own password-match check,
// UserService::validateMailAddress()'s own format check) -- the server
// remains authoritative either way. Reuses each field's own existing
// sibling .error-message <p> (the same element the required-field check
// above already shows/hides), rather than adding new markup. Scoped to
// each page's own root section id (#register-form/#password-form) --
// this file loads on profile.latte too (see ProfileView::pageAssets()),
// which reuses the SAME #password/#password_conf ids for an unrelated
// field pair (current-password re-entry + new-password confirmation,
// paired with #password_new, not #password) -- an unscoped bind here
// would silently misfire there.
function pwg_checkPasswordMatchStdPages(
  rootId: string,
  pass1Id: string,
  pass2Id: string,
) {
  const root = jQuery("#" + rootId);
  if (root.length === 0) {
    return;
  }
  const pass1 = root.find("#" + pass1Id);
  const pass2 = root.find("#" + pass2Id);
  if (pass1.length === 0 || pass2.length === 0) {
    return;
  }
  const errorMessage = pass2.closest(".column-flex").find(".error-message");

  function check() {
    if (pass2.val() !== "" && pass1.val() !== pass2.val()) {
      errorMessage
        .html(
          '<i class="gallery-icon-attention-circled"></i> ' +
            pwg_getPageString("The passwords do not match"),
        )
        .show();
    } else {
      errorMessage.hide();
    }
  }

  pass1.on("blur keyup", check);
  pass2.on("blur keyup", check);
}

function pwg_checkEmailFormatStdPages(rootId: string, fieldId: string) {
  const root = jQuery("#" + rootId);
  if (root.length === 0) {
    return;
  }
  const field = root.find("#" + fieldId);
  if (field.length === 0) {
    return;
  }
  const errorMessage = field.closest(".column-flex").find(".error-message");

  function check() {
    if (
      field.val() !== "" &&
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(field.val() ?? ""))
    ) {
      errorMessage
        .html(
          '<i class="gallery-icon-attention-circled"></i> ' +
            pwg_getPageString(
              "mail address must be like xxx@yyy.eee (example : jack@altern.org)",
            ),
        )
        .show();
    } else {
      errorMessage.hide();
    }
  }

  field.on("blur", check);
}

jQuery(document).ready(function () {
  pwg_checkPasswordMatchStdPages("register-form", "password", "password_conf");
  pwg_checkEmailFormatStdPages("register-form", "mail_address");
  pwg_checkPasswordMatchStdPages(
    "password-form",
    "use_new_pwd",
    "passwordConf",
  );
});
