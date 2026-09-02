import { pwg_getPageString } from "../../default/js/page-data";
import {
  css,
  data as readData,
  delegate,
  hide,
  on,
  ready,
  show,
} from "../../default/js/vendor/dom";

/** jQuery's `.html(value)` writes to every element of the set. */
function setHtmlAll(targets: ArrayLike<Element>, value: string): void {
  for (const element of Array.from(targets)) {
    element.innerHTML = value;
  }
}

const modeCookie = getCookie("mode");
if ("" !== modeCookie) {
  toggle_mode(modeCookie);
} else {
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  toggle_mode(prefersDark ? "dark" : "light");
}

window
  .matchMedia("(prefers-color-scheme: dark)")
  .addEventListener("change", (event) => {
    const newMode = event.matches ? "dark" : "light";
    toggle_mode(newMode);
  });

ready(function () {
  //Override empty input message
  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", function (e) {
      let isValid = true;

      document.querySelectorAll(".column-flex").forEach((column) => {
        // Because we overid the default browser error message
        // we need to distinguish which fields are now required
        // To do this we use data-required="true" on the input
        //
        // `.data()`, not `dataset`: data-required="true" is the string
        // "true" in the DOM and the boolean `true` through jQuery's own
        // coercion, and this comparison is against the boolean.
        const inputs = column.querySelectorAll<HTMLInputElement>("input");
        const input = inputs[0];
        if (input !== undefined && readData(input, "required") === true) {
          const errorMessages = column.querySelectorAll(".error-message");
          if (!input.value.trim()) {
            e.preventDefault();
            input.setCustomValidity(""); // Override browser tooltip (empty space hides it)
            show(errorMessages);
            isValid = false;
          } else {
            input.setCustomValidity("");
            hide(errorMessages);
          }
        }
      });

      // jQuery turns a handler's `false` return into preventDefault plus
      // stopPropagation; a `true` return does nothing at all.
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: isValid is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
      if (!isValid) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  });

  // Hide error message and reset validation on input
  document
    .querySelectorAll<HTMLInputElement>(".column-flex input")
    .forEach((input) => {
      input.addEventListener("input", function () {
        const errorMessages =
          input.closest(".column-flex")?.querySelectorAll(".error-message") ??
          [];
        input.setCustomValidity(""); // Reset browser tooltip
        hide(errorMessages);
      });
    });

  // Hide error message when user starts typing
  document
    .querySelectorAll<HTMLInputElement>(".column-flex input")
    .forEach((input) => {
      input.addEventListener("input", function () {
        hide(
          input.closest(".column-flex")?.querySelectorAll(".error-message") ??
            [],
        );
      });
    });
});

function toggle_mode(mode: string) {
  setCookie("mode", mode, 30);
  const logo = document.getElementById(
    "piwigo-logo",
  ) as HTMLImageElement | null;
  const lightToggle = document.getElementById("toggle_mode_light");
  const darkToggle = document.getElementById("toggle_mode_dark");
  const root = document.getElementById("mode");
  if ("dark" === mode) {
    //Dark mode
    if (lightToggle !== null) {
      hide(lightToggle);
    }
    if (darkToggle !== null) {
      show(darkToggle);
    }
    root?.classList.add("dark");
    root?.classList.remove("light");
    if (logo) {
      logo.src = logo.dataset["logoDark"]!;
    }
  } else {
    //Light mode
    if (darkToggle !== null) {
      hide(darkToggle);
    }
    if (lightToggle !== null) {
      show(lightToggle);
    }
    root?.classList.add("light");
    root?.classList.remove("dark");
    if (logo) {
      logo.src = logo.dataset["logoLight"]!;
    }
  }
}

function setCookie(cname: string, cvalue: string, exdays: number) {
  const d = new Date();
  d.setTime(d.getTime() + exdays * 24 * 60 * 60 * 1000);
  const expires = "expires=" + d.toUTCString();
  document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
  if (cname === "lang") {
    location.reload();
  }
}

function getCookie(cname: string) {
  const name = cname + "=";
  const decodedCookie = decodeURIComponent(document.cookie);
  const ca = decodedCookie.split(";");
  for (let c of ca) {
    while (c.startsWith(" ")) {
      c = c.substring(1);
    }
    if (c.startsWith(name)) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}

document.querySelectorAll(".togglePassword").forEach((element) => {
  element.addEventListener("click", function (e) {
    // The original reads `e.target`, not the bound element, so a click
    // landing on a child of the toggle styles that child. Kept as-is.
    const toggle = e.target;
    if (!(toggle instanceof HTMLElement)) {
      return;
    }

    // `.siblings("input")` -- the parent's other children, never the
    // element itself.
    const input = Array.from(toggle.parentElement?.children ?? []).find(
      (sibling) => sibling !== toggle && sibling.matches("input"),
    ) as HTMLInputElement | undefined;
    if (input === undefined) {
      return;
    }

    if (input.type === "password") {
      input.type = "text";
      css(toggle, "color", "#ff7700");
    } else {
      input.type = "password";
      css(toggle, "color", "#898989");
    }
  });
});

document.querySelectorAll("#other-languages a").forEach((link) => {
  link.addEventListener("click", function (e) {
    const target = e.target;
    if (!(target instanceof Element)) {
      return;
    }
    const clickedUrl = new URL(target.getAttribute("href")!);
    const selectedLang = clickedUrl.searchParams.get("lang");

    if (selectedLang !== null && selectedLang !== "") {
      setCookie("lang", selectedLang, 1);
    }
  });
});

document
  .getElementById("toggle_mode_light")
  ?.addEventListener("click", function () {
    toggle_mode("dark");
  });

document
  .getElementById("toggle_mode_dark")
  ?.addEventListener("click", function () {
    toggle_mode("light");
  });

const otherLanguages = document.getElementById("other-languages");
if (otherLanguages !== null) {
  // Delegated: the listener is on the container, but runs with the matched
  // descendant as its subject.
  delegate(
    otherLanguages,
    "click",
    "[data-lang-code]",
    function (this: Element) {
      setCookie("lang", readData(this, "langCode") as string, 30);
    },
  );
}

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
  const root = document.getElementById(rootId);
  if (root === null) {
    return;
  }
  const pass1 = root.querySelector<HTMLInputElement>("#" + pass1Id);
  const pass2 = root.querySelector<HTMLInputElement>("#" + pass2Id);
  if (pass1 === null || pass2 === null) {
    return;
  }
  const errorMessages =
    pass2.closest(".column-flex")?.querySelectorAll(".error-message") ?? [];

  const check = (): void => {
    if (pass2.value !== "" && pass1.value !== pass2.value) {
      setHtmlAll(
        errorMessages,
        '<i class="gallery-icon-attention-circled"></i> ' +
          pwg_getPageString("The passwords do not match"),
      );
      show(errorMessages);
    } else {
      hide(errorMessages);
    }
  };

  // Two types in one registration, as jQuery splits them.
  on(pass1, "blur keyup", check);
  on(pass2, "blur keyup", check);
}

function pwg_checkEmailFormatStdPages(rootId: string, fieldId: string) {
  const root = document.getElementById(rootId);
  if (root === null) {
    return;
  }
  const field = root.querySelector<HTMLInputElement>("#" + fieldId);
  if (field === null) {
    return;
  }
  const errorMessages =
    field.closest(".column-flex")?.querySelectorAll(".error-message") ?? [];

  const check = (): void => {
    if (field.value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
      setHtmlAll(
        errorMessages,
        '<i class="gallery-icon-attention-circled"></i> ' +
          pwg_getPageString(
            "mail address must be like xxx@yyy.eee (example : jack@altern.org)",
          ),
      );
      show(errorMessages);
    } else {
      hide(errorMessages);
    }
  };

  field.addEventListener("blur", check);
}

ready(function () {
  pwg_checkPasswordMatchStdPages("register-form", "password", "password_conf");
  pwg_checkEmailFormatStdPages("register-form", "mail_address");
  pwg_checkPasswordMatchStdPages(
    "password-form",
    "use_new_pwd",
    "passwordConf",
  );
});
