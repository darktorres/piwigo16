import type { operations } from "../../../openapi/client/schema";
import { pwgToaster } from "./toaster";
import { sprintf } from "../../admin/default/js/common";

import { pwg_getPageData, pwg_getPageString } from "../../default/js/page-data";
import { ajax, type AjaxResponse } from "../../default/js/vendor/ajax";
import {
  addClass,
  attr,
  attrOf,
  data,
  fadeIn,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  is,
  isVisible,
  off,
  on,
  ready,
  remove,
  removeClass,
  setChecked,
  setData,
  setVal,
  show,
  text,
  textOf,
  trigger,
  val,
} from "../../default/js/vendor/dom";
export {};

interface DefaultUserValues {
  nb_image_page: number;
  // "true"/"false" literal strings, matching DefaultUserProfileValues.php's
  // own JS-inline-expression convention -- not real booleans.
  expand: string;
  show_nb_comments: string;
  show_nb_hits: string;
  recent_period: number;
}

interface UserPreferences {
  username: string;
  email: string | null;
  nb_image_page: string;
  theme: string;
  language: string;
  recent_period: string;
  opt_album: boolean;
  opt_comment: boolean;
  opt_hits: boolean;
}

// Genuinely heterogeneous per-caller field set (email-only, the full
// preferences form, the password form, a dynamic plugin-extension
// form's own field names, or one of the API-key endpoints' own small
// param shapes) -- narrowed from `any` to the real primitive value
// types every one of these fields actually holds, not further.
type ProfileParams = Record<string, string | number | boolean | undefined>;

type ApiKeyEntry =
  operations["sessionApiKeyList"]["responses"][200]["content"]["application/json"]["apiKeys"][number];

let user: UserPreferences = {
  username: pwg_getPageData<string>("username"),
  email: pwg_getPageData<string | null>("email"),
  nb_image_page: val(
    document.querySelectorAll('input[name="nb_image_page"]'),
  ) as string,
  theme: val(document.querySelectorAll('select[name="theme"]')) as string,
  language: val(document.querySelectorAll('select[name="language"]')) as string,
  recent_period: val(
    document.querySelectorAll('input[name="recent_period"]'),
  ) as string,
  opt_album: is(document.querySelectorAll("#opt_album"), ":checked"),
  opt_comment: is(document.querySelectorAll("#opt_comment"), ":checked"),
  opt_hits: is(document.querySelectorAll("#opt_hits"), ":checked"),
};

const canUpdatePreferences = pwg_getPageData<boolean>(
  "allow_user_customization",
);
const canUpdatePassword = pwg_getPageData<boolean>("can_update_password");
// One '#save_<block id>' selector per plugin-extension block that opts into
// the standard save button (profile.latte's PLUGINS_PROFILE extension
// point) -- read from the DOM instead of a per-iteration exposeData() push,
// since the rendered button ids already carry everything needed and this
// is otherwise "same behavior" either way (see myInfoBody()'s own note:
// currently always empty, no live plugin in this rewrite).
const standardSaveSelector = Array.from(
  document.querySelectorAll('.form.plugins .save button[id^="save_"]'),
).map((el) => "#" + el.id);
const defaultUserValues = pwg_getPageData<DefaultUserValues>(
  "default_user_values",
);
// Real, pre-existing behavior, not a bug this phase fixes: opt_album/
// opt_comment/opt_hits below are the "true"/"false" *string* literals
// DefaultUserProfileValues.php's own docblock describes -- and
// `#opt_album`'s own `.prop("checked", preferencesDefaultValues.opt_album)`
// call site (below) treats any non-empty string, including the literal
// text "false", as truthy. "Reset to defaults" therefore always checks
// these 3 boxes regardless of the real default value. No compile error
// forces a fix here (`.prop()`'s value param isn't narrowly typed), so
// left as-is rather than silently changed; flagged for visibility.
const preferencesDefaultValues = {
  nb_image_page: defaultUserValues.nb_image_page,
  recent_period: defaultUserValues.recent_period,
  opt_album: defaultUserValues.expand,
  opt_comment: defaultUserValues.show_nb_comments,
  opt_hits: defaultUserValues.show_nb_hits,
};
const selected_date = pwg_getPageData<string>("selected_date");
const can_manage_api = pwg_getPageData<boolean>("api_can_manage");

const str_copy_key_id = pwg_getPageString("ID copied.");
const str_copy_key_secret = pwg_getPageString(
  "Secret copied. Keep it in a safe place.",
);
const str_cant_copy = pwg_getPageString(
  "Impossible to copy automatically. Please copy manually.",
);
const str_api_added = pwg_getPageString(
  "The api key has been successfully created.",
);
const str_show_expired = pwg_getPageString("Show expired keys");
const str_hide_expired = pwg_getPageString("Hide expired keys");
const str_handle_error = pwg_getPageString("An error has occured");
const str_infos_saved = pwg_getPageString("Your changes have been applied.");
const str_revoke_key = pwg_getPageString(
  'Do you really want to revoke the "%s" API key?',
);
const str_api_revoked = pwg_getPageString(
  "API Key has been successfully revoked.",
);
const str_api_edited = pwg_getPageString(
  "API Key has been successfully edited.",
);
const no_time_elapsed = pwg_getPageString("right now");

let PWG_TOKEN: string;
ready(function () {
  PWG_TOKEN = val(document.querySelectorAll("#pwg_token")) as string;

  on(
    document.querySelectorAll(".profile-section .display-section"),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const display = data(el, "display") as string;
      const element = document.getElementById(display)!;
      const arrow = find(el, ".display-btn");

      if (hasClass(element, "open")) {
        // close
        element.style.maxHeight = String(element.scrollHeight) + "px";
        void element.offsetHeight;
        element.style.maxHeight = "1px";
        removeClass(element, "open");
        addClass(arrow, "close");
      } else {
        // open
        addClass(element, "open");
        resetSection(display);
        removeClass(arrow, "close");
      }
    },
  );

  setTimeout(() => {
    trigger(
      document.querySelectorAll("#account-section .display-section"),
      "click",
    );
  }, 100);

  on(document.querySelectorAll("#save_account"), "click", function () {
    const mail = val(document.querySelectorAll("#email")) as string;
    if (!mail || mail === "") {
      show(document.querySelectorAll("#email_error"));
      return;
    }
    setInfos({ email: mail });
  });

  if (canUpdatePreferences) {
    on(document.querySelectorAll("#save_preferences"), "click", function () {
      const values = {
        nb_image_page: val(
          document.querySelectorAll("#nb_image_page"),
        ) as string,
        theme: val(document.querySelectorAll('select[name="theme"]')) as string,
        language: val(
          document.querySelectorAll('select[name="language"]'),
        ) as string,
        recent_period: val(
          document.querySelectorAll("#recent_period"),
        ) as string,
        expand: is(document.querySelectorAll("#opt_album"), ":checked"),
        show_nb_comments: is(
          document.querySelectorAll("#opt_comment"),
          ":checked",
        ),
        show_nb_hits: is(document.querySelectorAll("#opt_hits"), ":checked"),
      };

      if (values.nb_image_page === "") {
        show(document.querySelectorAll("#error_nb_image"));
        return;
      }

      if (values.recent_period === "") {
        show(document.querySelectorAll("#error_period"));
        return;
      }

      setInfos({ ...values });
    });

    on(document.querySelectorAll("#reset_preferences"), "click", function () {
      setVal(
        document.querySelectorAll('input[name="nb_image_page"]'),
        user.nb_image_page,
      );
      setVal(document.querySelectorAll('select[name="theme"]'), user.theme);
      setVal(
        document.querySelectorAll('select[name="language"]'),
        user.language,
      );
      setVal(
        document.querySelectorAll('input[name="recent_period"]'),
        user.recent_period,
      );
      setChecked(document.querySelectorAll("#opt_album"), user.opt_album);
      setChecked(document.querySelectorAll("#opt_comment"), user.opt_comment);
      setChecked(document.querySelectorAll("#opt_hits"), user.opt_hits);
    });

    on(document.querySelectorAll("#default_preferences"), "click", function () {
      setVal(
        document.querySelectorAll('input[name="nb_image_page"]'),
        String(preferencesDefaultValues.nb_image_page),
      );
      setVal(
        document.querySelectorAll('input[name="recent_period"]'),
        String(preferencesDefaultValues.recent_period),
      );
      // See preferencesDefaultValues' own docblock -- these 3 lines treat
      // any non-empty string (including the literal text "false") as
      // truthy, faithfully matching the original's identical
      // `.prop("checked", stringValue)` behavior.
      setChecked(
        document.querySelectorAll("#opt_album"),
        Boolean(preferencesDefaultValues.opt_album),
      );
      setChecked(
        document.querySelectorAll("#opt_comment"),
        Boolean(preferencesDefaultValues.opt_comment),
      );
      setChecked(
        document.querySelectorAll("#opt_hits"),
        Boolean(preferencesDefaultValues.opt_hits),
      );
    });
  }

  if (canUpdatePassword) {
    on(document.querySelectorAll("#save_password"), "click", function () {
      const passwords = {
        password: val(document.querySelectorAll("#password")) as string,
        new_password: val(document.querySelectorAll("#password_new")) as string,
        conf_new_password: val(
          document.querySelectorAll("#password_conf"),
        ) as string,
      };
      if (
        passwords.password === "" ||
        passwords.new_password === "" ||
        passwords.conf_new_password === ""
      ) {
        document.querySelectorAll("#password-section input").forEach((el) => {
          if (val(el) === "") {
            const parent = el.parentElement;
            if (parent !== null) {
              show(siblingsOf(parent));
            }
          }
        });
        return;
      }
      setInfos({ ...passwords });
      setVal(document.querySelectorAll("#password-section input"), "");
    });

    // Live mirror of ProfileFormHandler's own server-side password-match
    // check -- the AJAX save above remains authoritative either way.
    // Reuses #password_conf's own existing sibling .error-message <p>,
    // same element/convention the empty-field check above already
    // shows/hides.
    (function () {
      const newPassword = document.getElementById("password_new")!;
      const confirmPassword = document.getElementById("password_conf")!;
      const errorMessage = find(
        confirmPassword.closest(".column-flex")!,
        ".error-message",
      );

      function check() {
        if (
          val(confirmPassword) !== "" &&
          val(newPassword) !== val(confirmPassword)
        ) {
          html(
            errorMessage,
            '<i class="gallery-icon-attention-circled"></i> ' +
              pwg_getPageString("The passwords do not match"),
          );
          show(errorMessage);
        } else {
          hide(errorMessage);
        }
      }

      on(newPassword, "blur keyup", check);
      on(confirmPassword, "blur keyup", check);
    })();
  }

  standardSaveSelector.forEach((selector, i) => {
    on(document.querySelectorAll(selector), "click", function () {
      const values: ProfileParams = {};
      find(
        document.querySelectorAll(`#${i}-section`),
        "input, textarea, select",
      ).forEach((element) => {
        const inputName = attrOf(element, "name");
        const inputValue = val(element) as string;
        values[inputName!] = inputValue;
      });
      setInfos({ ...values });
    });
  });

  // API KEY BELOW
  if (!can_manage_api) {
    hide(document.querySelectorAll(".can-manage"));
    show(document.querySelectorAll("#cant_manage_api"));
    return;
  }
  on(document.querySelectorAll("#new_apikey"), "click", function () {
    openApiModal();
  });

  on(
    document.querySelectorAll("#close_api_modal, #cancel_apikey"),
    "click",
    function () {
      closeApiModal();
    },
  );

  on(document.querySelectorAll("#close_api_modal_edit"), "click", function () {
    closeApiEditModal();
  });

  on(
    document.querySelectorAll("#close_api_modal_revoke, #cancel_api_revoke"),
    "click",
    function () {
      closeApiRevokeModal();
    },
  );

  on(
    document.querySelectorAll("#show_expired_list"),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const api_list_expired = document.getElementById("api_key_list_expired")!;
      const isOpen = data(el, "show");
      if (!isOpen) {
        api_list_expired.style.maxHeight = "max-content";
        text(el, str_hide_expired);
      } else {
        api_list_expired.style.maxHeight = "0";
        text(el, str_show_expired);
      }

      setData(el, "show", !isOpen);

      resetSection("apikey-display", false, true);
    },
  );

  on(window, "keydown", function (e: Event) {
    const key = (e as KeyboardEvent).key;
    const haveApiModal = isVisible(document.getElementById("api_modal")!);
    const haveApiEditModal = isVisible(
      document.getElementById("api_modal_edit")!,
    );
    const haveApiRevokeModal = isVisible(
      document.getElementById("api_modal_revoke")!,
    );
    if (haveApiModal && key === "Escape") {
      closeApiModal();
    }
    if (haveApiEditModal && key === "Escape") {
      closeApiEditModal();
    }
    if (haveApiRevokeModal && key === "Escape") {
      closeApiRevokeModal();
    }
  });

  on(
    document.querySelectorAll('select[name="api_expiration"]'),
    "change",
    function (event: Event) {
      const custom_date = document.getElementById("api_custom_date")!;
      const value = val(event.currentTarget as Element);
      if ("custom" === value) {
        custom_date.style.display = "flex";
      } else {
        custom_date.style.display = "none";
      }
      hide(document.querySelectorAll("#error_api_key_date"));
    },
  );

  on(document.querySelectorAll("#api_expiration_date"), "change", function () {
    hide(document.querySelectorAll("#error_api_key_date"));
  });

  getAllApiKeys();
});

/** `.siblings()` -- every other child of the element's own parent. */
function siblingsOf(el: Element): Element[] {
  const parent = el.parentElement;
  if (parent === null) {
    return [];
  }
  return Array.from(parent.children).filter((child) => child !== el);
}

// Callers (email/preferences/password/the plugin-extension
// standardSaveSelector loop, currently always empty -- see profile.latte's
// PLUGINS_PROFILE extension point, no live plugin in this rewrite) use
// snake_case field names -- translated to PATCH /api/v1/session's
// camelCase body here. Any key this doesn't recognise passes through
// unchanged and is silently ignored server-side.
function myInfoBody(params: ProfileParams) {
  const rename: Record<string, string> = {
    nb_image_page: "nbImagePage",
    recent_period: "recentPeriod",
    show_nb_comments: "showNbComments",
    show_nb_hits: "showNbHits",
    new_password: "newPassword",
    conf_new_password: "confNewPassword",
  };
  const numeric = ["nbImagePage", "recentPeriod"];
  const body: ProfileParams = {};
  Object.keys(params).forEach((key) => {
    const newKey = rename[key] || key;
    body[newKey] = numeric.includes(newKey) ? Number(params[key]) : params[key];
  });
  return body;
}

interface ApiKeyRequestSpec {
  url: string;
  httpMethod: string;
  body: ProfileParams | null;
}

const API_KEY_ENDPOINTS: Record<
  string,
  (params: ProfileParams) => ApiKeyRequestSpec
> = {
  "pwg.users.setMyInfo": (params) => ({
    url: "api/v1/session",
    httpMethod: "PATCH",
    body: myInfoBody(params),
  }),
  "pwg.users.api_key.create": (params) => ({
    url: "api/v1/session/api-keys",
    httpMethod: "POST",
    body: { keyName: params["key_name"], duration: params["duration"] },
  }),
  "pwg.users.api_key.edit": (params) => ({
    url: `api/v1/session/api-keys/${String(params["pkid"])}`,
    httpMethod: "PATCH",
    body: { keyName: params["key_name"] },
  }),
  "pwg.users.api_key.revoke": (params) => ({
    url: `api/v1/session/api-keys/${String(params["pkid"])}`,
    httpMethod: "DELETE",
    body: null,
  }),
};

function setInfos(
  params: ProfileParams,
  method: keyof typeof API_KEY_ENDPOINTS = "pwg.users.setMyInfo",
  // The real response shape genuinely differs per dispatched endpoint
  // (SessionStatus / ApiKeyCreated / no body at all for the 204 edit
  // and revoke endpoints) -- each real call site below narrows its own
  // `data`/`res` parameter to the shape that endpoint actually returns.
  callback: ((data: any) => void) | null = null,
  errCallback: ((e: AjaxResponse) => void) | null = null,
) {
  // for debug
  // console.log('setInfos', params);
  const { url, httpMethod, body } = API_KEY_ENDPOINTS[method]!(params);
  void ajax({
    url: url,
    method: httpMethod,
    contentType: "application/json",
    dataType: "json",
    data: body !== null ? JSON.stringify(body) : undefined,
    headers: { "X-CSRF-Token": PWG_TOKEN },
    success: (response) => {
      user = { ...user, ...params };
      if (typeof callback === "function") {
        callback(response);
        return;
      }
      pwgToaster({ text: str_infos_saved, icon: "success" });
    },
    error: function (e) {
      pwgToaster({
        text:
          (e.responseJSON as { detail?: string } | undefined)?.detail ??
          str_handle_error,
        icon: "error",
      });
      if (typeof errCallback === "function") {
        errCallback(e);
        return;
      }
    },
  });
}

function getAllApiKeys(reset: boolean = false) {
  void ajax({
    url: "api/v1/session/api-keys",
    type: "GET",
    dataType: "json",
    success: function (
      res: operations["sessionApiKeyList"]["responses"][200]["content"]["application/json"],
    ) {
      if (res.apiKeys.length === 0) {
        // No keys
      } else {
        AddApiLine(res.apiKeys, reset);
      }
    },
    error: function (e) {
      pwgToaster({
        text:
          (e.responseJSON as { detail?: string } | undefined)?.detail ??
          str_handle_error + "getAllApiKeys",
        icon: "error",
      });
    },
  });
}

function AddApiLine(lines: ApiKeyEntry[], reset: boolean) {
  const api_list = document.getElementById("api_key_list")!;
  const api_list_expired = document.getElementById("api_key_list_expired")!;

  remove(
    document.querySelectorAll(
      "#api_key_list .api-tab-line:not(.template-api), #api_key_list .api-tab-collapse:not(.template-api)",
    ),
  );
  remove(
    document.querySelectorAll(
      "#api_key_list_expired .api-tab-line:not(.template-api), #api_key_list_expired .api-tab-collapse:not(.template-api)",
    ),
  );

  lines.forEach((line) => {
    const api_line = document
      .getElementById("api_line")!
      .cloneNode(true) as Element;
    const api_collapse = document
      .getElementById("api_collapse")!
      .cloneNode(true) as Element;
    const tmp_id = line.authKey.slice(24, 34);

    removeClass(api_line, "template-api");
    addClass(api_line, "api-tab");
    attr(api_line, "id", `api_${tmp_id}`);
    setData(find(api_line, ".icon-collapse")[0]!, "api", tmp_id);
    text(find(api_line, ".api_name"), line.apikeyName);
    attr(find(api_line, ".api_name"), "title", line.apikeyName);
    text(find(api_line, ".api_creation"), line.createdOn);
    text(find(api_line, ".api_last_use"), line.lastUsedOn || no_time_elapsed);
    attr(
      find(api_line, ".api_last_use"),
      "title",
      line.lastUsedOn || no_time_elapsed,
    );
    text(find(api_line, ".api_expiration"), line.expiration);
    attr(find(api_line, ".api-icon-action"), "data-api", `api_${tmp_id}`);
    attr(find(api_line, ".api-icon-action"), "data-pkid", line.authKey);

    attr(api_collapse, "id", `api_collapse_${tmp_id}`);
    removeClass(api_collapse, "template-api");
    text(find(api_collapse, ".api_key"), line.authKey);
    attr(find(api_collapse, ".icon-clone"), "data-copy", line.authKey);
    attr(
      find(api_collapse, ".icon-clone"),
      "data-success",
      `api_copy_success_${tmp_id}`,
    );
    attr(find(api_collapse, ".api-copy"), "id", `api_copy_success_${tmp_id}`);

    if (!line.revokedOn && !line.isExpired) {
      api_list.appendChild(api_line);
      api_line.after(api_collapse);
    } else {
      show(document.querySelectorAll("#show_expired_list"));
      api_list_expired.appendChild(api_line);
      api_line.after(api_collapse);
      remove(find(api_line, ".api-icon-action"));
      if (line.isExpired) {
        html(
          find(api_line, ".api_expiration"),
          `<i class="gallery-icon-skull api-skull"></i> <span>${line.expiredOn}</span>`,
        );
      } else {
        // Non-null: this branch is `!line.isExpired` inside the outer
        // `else` of `!line.revokedOn && !line.isExpired`, so
        // `line.revokedOn` must be truthy here.
        html(
          find(api_line, ".api_expiration"),
          `<i class="gallery-icon-skull api-skull"></i> <span>${line.revokedOn!}</span>`,
        );
      }
    }
  });

  apiLineEvent();
  if (reset) {
    resetSection("apikey-display");
  }
}

function apiLineEvent() {
  const iconCollapse = document.querySelectorAll(".icon-collapse");
  off(iconCollapse, "click");
  on(iconCollapse, "click", function (event: Event) {
    const el = event.currentTarget as Element;
    const apiId = data(el, "api") as string;
    const api_collapse = document.getElementById(`api_collapse_${apiId}`)!;
    const api_line = document.getElementById(`api_${apiId}`)!;

    if (isVisible(api_collapse)) {
      removeClass(api_collapse, "open");
      removeClass(api_line, "open");
      addClass(find(api_line, ".icon-collapse"), "close");
      api_collapse.style.display = "none";
      addClass(find(api_collapse, ".api-copy"), "api-hide");
    } else {
      addClass(api_collapse, "open");
      addClass(api_line, "open");
      removeClass(find(api_line, ".icon-collapse"), "close");
      api_collapse.style.display = "grid";
    }

    resetSection("apikey-display", false, true);
  });

  const cloneButtons = document.querySelectorAll(
    ".api-tab-collapse .icon-clone",
  );
  off(cloneButtons, "click");
  on(cloneButtons, "click", function (event: Event) {
    const el = event.currentTarget as Element;
    const data_to_copy = data(el, "copy") as string;
    const selector = data(el, "success") as string;
    copyToClipboard(data_to_copy, str_copy_key_id, `#${selector}`);
  });

  const editButtons = document.querySelectorAll(".api-tab-line .edit-mode");
  off(editButtons, "click");
  on(editButtons, "click", function (event: Event) {
    const parent = (event.currentTarget as Element).parentElement;
    const selector = parent !== null ? (data(parent, "api") as string) : "";
    openApiEditModal(`#${selector}`);
  });

  const deleteButtons = document.querySelectorAll(".api-tab-line .delete-mode");
  off(deleteButtons, "click");
  on(deleteButtons, "click", function (event: Event) {
    const parent = (event.currentTarget as Element).parentElement;
    const selector = parent !== null ? (data(parent, "api") as string) : "";
    openApiRevokeModal(`#${selector}`);
  });
}

function resetSection(
  selector: string,
  scroll: boolean = true,
  maxContent: boolean = false,
) {
  const element = document.getElementById(selector)!;
  const scrollH = maxContent
    ? "max-content"
    : String(element.scrollHeight) + "px";
  element.style.maxHeight = scrollH;

  if ("account-display" !== selector && scroll) {
    setTimeout(() => {
      const el = document.getElementById(`${selector.split("-")[0]!}-section`)!;
      el.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    }, 200);
  }
}

function openApiModal() {
  fadeIn(document.querySelectorAll("#api_modal"));
  document.getElementById("api_key_name")?.focus();
  saveApiKeyEvent();
}

function closeApiModal() {
  fadeOut(document.querySelectorAll("#api_modal"), () => {
    setVal(document.querySelectorAll("#api_key_name"), "");
    setVal(
      document.querySelectorAll('select[name="api_expiration"]'),
      selected_date,
    );
    trigger(
      document.querySelectorAll('select[name="api_expiration"]'),
      "change",
    );
    setVal(document.querySelectorAll("#api_expiration_date"), "");

    setVal(document.querySelectorAll("#api_secret_key"), "");
    hide(document.querySelectorAll("#retrieves_keyapi"));
    show(document.querySelectorAll("#generate_keyapi"));
    (document.getElementById(
      "done_apikey",
    ) as HTMLButtonElement | null)!.disabled = true;
    addClass(
      document.querySelectorAll("#api_key_copy_success, #api_id_copy_success"),
      "api-hide",
    );
  });
  unbindApiKeyEvents();
}

function successApiModal(secret: string, id: string) {
  setVal(document.querySelectorAll("#api_secret_key"), secret);
  setVal(document.querySelectorAll("#api_id_key"), id);

  hide(document.querySelectorAll("#generate_keyapi"));
  fadeIn(document.querySelectorAll("#retrieves_keyapi"));

  const apiSecretCopy = document.querySelectorAll("#api_secret_copy");
  off(apiSecretCopy, "click");
  on(apiSecretCopy, "click", function () {
    copyToClipboard(secret, str_copy_key_secret, "#api_key_copy_success");

    const doneButton = document.getElementById(
      "done_apikey",
    ) as HTMLButtonElement;
    doneButton.disabled = false;
    on(doneButton, "click", closeApiModal);
  });

  const apiIdCopy = document.querySelectorAll("#api_id_copy");
  off(apiIdCopy, "click");
  on(apiIdCopy, "click", function () {
    copyToClipboard(id, str_copy_key_id, "#api_id_copy_success");
  });
}

//api edit modal
function openApiEditModal(selector: string) {
  const target = document.querySelector(selector)!;
  const value = textOf(find(target, ".api_name"));
  const pkid = data(find(target, ".api-icon-action")[0]!, "pkid") as string;
  setVal(document.querySelectorAll("#api_key_edit"), value);
  fadeIn(document.querySelectorAll("#api_modal_edit"));
  document.getElementById("api_key_edit")?.focus();
  saveApiEditEvents(pkid);
}

function closeApiEditModal() {
  fadeOut(document.querySelectorAll("#api_modal_edit"), () => {
    setVal(document.querySelectorAll("#api_key_edit"), "");
    unbindApiEditEvents();
  });
}

function saveApiEditEvents(pkid: string) {
  on(document.querySelectorAll("#save_api_edit"), "click", function () {
    const value = val(document.querySelectorAll("#api_key_edit")) as string;

    if ("" === value) {
      show(document.querySelectorAll("#error_api_key_edit"));
      return;
    }
    setInfos(
      {
        pkid,
        key_name: value,
      },
      "pwg.users.api_key.edit",
      // 204 No Content -- sessionApiKeyUpdate's real response has no body.
      (_res: unknown) => {
        pwgToaster({ text: str_api_edited, icon: "success" });
        getAllApiKeys(true);
        closeApiEditModal();
      },
    );
  });
}

function unbindApiEditEvents() {
  off(document.querySelectorAll("#save_api_edit"), "click");
}

// api revoke modal
function openApiRevokeModal(selector: string) {
  const target = document.querySelector(selector)!;
  const apiName = textOf(find(target, ".api_name"));
  const pkid = data(find(target, ".api-icon-action")[0]!, "pkid") as string;
  const text_ = sprintf(str_revoke_key, apiName);
  text(document.querySelectorAll("#api_modal_revoke_title"), text_);

  fadeIn(document.querySelectorAll("#api_modal_revoke"));
  saveApiRevokeEvents(pkid);
}

function closeApiRevokeModal() {
  fadeOut(document.querySelectorAll("#api_modal_revoke"), () => {
    text(document.querySelectorAll("#api_modal_revoke_title"), "");
    unbindApiRevokeEvents();
  });
}

function saveApiRevokeEvents(pkid: string) {
  on(document.querySelectorAll("#revoke_api_key"), "click", function () {
    setInfos(
      {
        pkid,
      },
      "pwg.users.api_key.revoke",
      // 204 No Content -- sessionApiKeyRevoke's real response has no body.
      (_res: unknown) => {
        pwgToaster({ text: str_api_revoked, icon: "success" });
        getAllApiKeys(true);
        closeApiRevokeModal();
      },
    );
  });
}

function unbindApiRevokeEvents() {
  off(document.querySelectorAll("#revoke_api_key"), "click");
}

function copyToClipboard(
  copy: string,
  message: string,
  selector: string | null = null,
) {
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
  if (window.isSecureContext && navigator.clipboard) {
    void navigator.clipboard.writeText(copy);
    if (selector) {
      removeClass(document.querySelectorAll(selector), "api-hide");
      // auto hide
      // setTimeout(() => {
      //   $(selector).addClass('api-hide');
      // }, 1000);
    } else {
      pwgToaster({ text: message, icon: "success" });
    }
    return true;
  } else {
    pwgToaster({ text: str_cant_copy, icon: "error" });
    return false;
  }
}

function saveApiKeyEvent() {
  const handler = () => {
    const api_name = val(document.querySelectorAll("#api_key_name")) as string;
    let api_duration: string | number | undefined = val(
      document.querySelectorAll('select[name="api_expiration"]'),
    );

    if (api_name === "") {
      show(document.querySelectorAll("#error_api_key_name"));
      return;
    }

    if (
      "custom" === api_duration &&
      !val(document.querySelectorAll("#api_expiration_date"))
    ) {
      show(document.querySelectorAll("#error_api_key_date"));
      return;
    }

    unbindApiKeyEvents();

    if ("custom" === api_duration) {
      const today = new Date();
      const custom_date = new Date(
        String(val(document.querySelectorAll("#api_expiration_date"))),
      );
      const one_day = 1000 * 60 * 60 * 24;
      const days = Math.ceil(
        (custom_date.getTime() - today.getTime()) / one_day,
      );
      api_duration = days;
    } else {
      // Genuine pre-existing bug found only by ESLint's stricter static
      // analysis: `Number(x) ?? 1` can never fall back to 1 -- `Number()`
      // returns `NaN` for invalid input, never `null`/`undefined`, so `??`
      // never triggers. `||` is the operator that actually treats `NaN`
      // (like `0`) as falsy, matching the real intended fallback.
      api_duration = Number(api_duration) || 1;
    }

    setInfos(
      {
        key_name: api_name,
        duration: api_duration,
      },
      "pwg.users.api_key.create",
      (
        res: operations["sessionApiKeyCreate"]["responses"][201]["content"]["application/json"],
      ) => {
        pwgToaster({ text: str_api_added, icon: "success" });
        getAllApiKeys(true);
        successApiModal(res.apikeySecret, res.authKey);
      },
      (_err: unknown) => {
        saveApiKeyEvent();
      },
    );
  };

  on(document.querySelectorAll("#save_apikey"), "click.apikey", handler);
  on(window, "keydown.apikey", function (e: Event) {
    if ((e as KeyboardEvent).key === "Enter") {
      e.preventDefault();
      handler();
    }
  });
}

function unbindApiKeyEvents() {
  const modal = document.getElementById("api_modal")!;
  off([modal, ...Array.from(modal.querySelectorAll("*"))], ".apikey");
  off(window, ".apikey");
}
