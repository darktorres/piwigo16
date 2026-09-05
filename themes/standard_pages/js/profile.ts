import type { operations } from "../../../openapi/client/schema";
import { pwgToaster } from "./toaster";
import { sprintf } from "../../default/js/sprintf";

import { pwg_getPageData, pwg_getPageString } from "../../default/js/pageData";
import {
  ajax,
  AjaxError,
  type AjaxResponse,
} from "../../default/js/vendor/utils/ajax";
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
  valueAt,
} from "../../default/js/vendor/utils/dom";

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
  nb_image_page:
    val(document.querySelectorAll('input[name="nb_image_page"]')) ?? "",
  theme: val(document.querySelectorAll('select[name="theme"]')) ?? "",
  language: val(document.querySelectorAll('select[name="language"]')) ?? "",
  recent_period:
    val(document.querySelectorAll('input[name="recent_period"]')) ?? "",
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
const selectedDate = pwg_getPageData<string>("selected_date");
const canManageApi = pwg_getPageData<boolean>("api_can_manage");

const strCopyKeyId = pwg_getPageString("ID copied.");
const strCopyKeySecret = pwg_getPageString(
  "Secret copied. Keep it in a safe place.",
);
const strCantCopy = pwg_getPageString(
  "Impossible to copy automatically. Please copy manually.",
);
const strApiAdded = pwg_getPageString(
  "The api key has been successfully created.",
);
const strShowExpired = pwg_getPageString("Show expired keys");
const strHideExpired = pwg_getPageString("Hide expired keys");
const strHandleError = pwg_getPageString("An error has occured");
const strInfosSaved = pwg_getPageString("Your changes have been applied.");
const strRevokeKey = pwg_getPageString(
  'Do you really want to revoke the "%s" API key?',
);
const strApiRevoked = pwg_getPageString(
  "API Key has been successfully revoked.",
);
const strApiEdited = pwg_getPageString("API Key has been successfully edited.");
const noTimeElapsed = pwg_getPageString("right now");

let pwgToken: string;
ready(function () {
  pwgToken = val(document.querySelectorAll("#pwg_token")) ?? "";

  on(
    document.querySelectorAll(".profile-section .display-section"),
    "click",
    function (this: Element) {
      const display = data<string>(this, "display");
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real .display-section's own data-display always names a real section id rendered on this page.
      const element = document.getElementById(display)!;
      const arrow = find(this, ".display-btn");

      if (hasClass(element, "open")) {
        // close
        element.style.maxHeight = String(element.scrollHeight) + "px";
        // eslint-disable-next-line sonarjs/void-use -- intentional: reading offsetHeight forces a synchronous layout reflow, which is the real effect wanted here (so the max-height transition below animates from a real starting point); `void` discards the read value, not the reflow.
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
    const mail = val(document.querySelectorAll("#email"));
    if (mail === undefined || mail === "") {
      show(document.querySelectorAll("#email_error"));
      return;
    }
    void setInfos({ email: mail });
  });

  if (canUpdatePreferences) {
    on(document.querySelectorAll("#save_preferences"), "click", function () {
      const values = {
        nb_image_page: val(document.querySelectorAll("#nb_image_page")) ?? "",
        theme: val(document.querySelectorAll('select[name="theme"]')) ?? "",
        language:
          val(document.querySelectorAll('select[name="language"]')) ?? "",
        recent_period: val(document.querySelectorAll("#recent_period")) ?? "",
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

      void setInfos({ ...values });
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
        password: val(document.querySelectorAll("#password")) ?? "",
        new_password: val(document.querySelectorAll("#password_new")) ?? "",
        conf_new_password:
          val(document.querySelectorAll("#password_conf")) ?? "",
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
      void setInfos({ ...passwords });
      setVal(document.querySelectorAll("#password-section input"), "");
    });

    // Live mirror of ProfileFormHandler's own server-side password-match
    // check -- the AJAX save above remains authoritative either way.
    // Reuses #password_conf's own existing sibling .error-message <p>,
    // same element/convention the empty-field check above already
    // shows/hides.
    (function () {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #password_new unconditionally in this branch.
      const newPassword = document.getElementById("password_new")!;
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #password_conf unconditionally in this branch.
      const confirmPassword = document.getElementById("password_conf")!;
      const errorMessage = find(
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- #password_conf is always rendered nested inside a real .column-flex ancestor.
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
        const inputValue = val(element);
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real input/textarea/select in a profile form section carries a real name attribute.
        values[inputName!] = inputValue;
      });
      void setInfos({ ...values });
    });
  });

  // API KEY BELOW
  if (!canManageApi) {
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
    function (this: Element) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_key_list_expired unconditionally.
      const apiListExpired = document.getElementById("api_key_list_expired")!;
      const isOpen = data(this, "show") === true;
      if (!isOpen) {
        apiListExpired.style.maxHeight = "max-content";
        text(this, strHideExpired);
      } else {
        apiListExpired.style.maxHeight = "0";
        text(this, strShowExpired);
      }

      setData(this, "show", !isOpen);

      resetSection("apikey-display", false, true);
    },
  );

  on(window, "keydown", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const { key } = e as KeyboardEvent;
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_modal unconditionally.
    const haveApiModal = isVisible(document.getElementById("api_modal")!);
    const haveApiEditModal = isVisible(
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_modal_edit unconditionally.
      document.getElementById("api_modal_edit")!,
    );
    const haveApiRevokeModal = isVisible(
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_modal_revoke unconditionally.
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
    function (this: Element) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_custom_date unconditionally.
      const customDate = document.getElementById("api_custom_date")!;
      const value = val(this);
      if ("custom" === value) {
        customDate.style.display = "flex";
      } else {
        customDate.style.display = "none";
      }
      hide(document.querySelectorAll("#error_api_key_date"));
    },
  );

  on(document.querySelectorAll("#api_expiration_date"), "change", function () {
    hide(document.querySelectorAll("#error_api_key_date"));
  });

  void getAllApiKeys();
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
    // eslint-disable-next-line sonarjs/no-hardcoded-passwords -- false positive: these are wire-format field-name keys (a rename map), not actual password values.
    new_password: "newPassword",
    // eslint-disable-next-line sonarjs/no-hardcoded-passwords -- see above.
    conf_new_password: "confNewPassword",
  };
  const numeric = ["nbImagePage", "recentPeriod"];
  const body: ProfileParams = {};
  Object.keys(params).forEach((key) => {
    const newKey = rename[key] ?? key;
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

async function setInfos(
  params: ProfileParams,
  method: keyof typeof API_KEY_ENDPOINTS = "pwg.users.setMyInfo",
  // The real response shape genuinely differs per dispatched endpoint
  // (SessionStatus / ApiKeyCreated / no body at all for the 204 edit
  // and revoke endpoints) -- each real call site below narrows its own
  // `data`/`res` parameter to the shape that endpoint actually returns.
  callback: ((data: any) => void) | null = null,
  errCallback: ((e: AjaxResponse) => void) | null = null,
): Promise<void> {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- method is typed as keyof typeof API_KEY_ENDPOINTS, always a real key.
  const { url, httpMethod, body } = API_KEY_ENDPOINTS[method]!(params);

  try {
    const response = await ajax({
      url: url,
      method: httpMethod,
      contentType: "application/json",
      dataType: "json",
      data: body !== null ? JSON.stringify(body) : undefined,
      headers: { "X-CSRF-Token": pwgToken },
    });

    user = { ...user, ...params };
    if (typeof callback === "function") {
      callback(response);
    } else {
      pwgToaster({ text: strInfosSaved, icon: "success" });
    }
  } catch (e) {
    pwgToaster({
      text:
        (e instanceof AjaxError
          ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back to strHandleError below.
            (e.responseJSON as { detail?: string } | undefined)?.detail
          : undefined) ?? strHandleError,
      icon: "error",
    });
    if (typeof errCallback === "function" && e instanceof AjaxError) {
      errCallback(e);
    }
  }
}

async function getAllApiKeys(reset = false): Promise<void> {
  try {
    const res = await ajax<
      operations["sessionApiKeyList"]["responses"][200]["content"]["application/json"]
    >({
      url: "api/v1/session/api-keys",
      type: "GET",
      dataType: "json",
    });

    if (res.apiKeys.length === 0) {
      // No keys
    } else {
      AddApiLine(res.apiKeys, reset);
    }
  } catch (e) {
    pwgToaster({
      text:
        (e instanceof AjaxError
          ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back below.
            (e.responseJSON as { detail?: string } | undefined)?.detail
          : undefined) ?? strHandleError + "getAllApiKeys",
      icon: "error",
    });
  }
}

function AddApiLine(lines: ApiKeyEntry[], reset: boolean) {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_key_list unconditionally.
  const apiList = document.getElementById("api_key_list")!;
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_key_list_expired unconditionally.
  const apiListExpired = document.getElementById("api_key_list_expired")!;

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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion, @typescript-eslint/no-non-null-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype. profile.latte renders the hidden #api_line template unconditionally.
    const apiLine = document
      .getElementById("api_line")!
      .cloneNode(true) as Element;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion, @typescript-eslint/no-non-null-assertion -- see comment above. profile.latte renders the hidden #api_collapse template unconditionally.
    const apiCollapse = document
      .getElementById("api_collapse")!
      .cloneNode(true) as Element;
    const tmpId = line.authKey.slice(24, 34);

    removeClass(apiLine, "template-api");
    addClass(apiLine, "api-tab");
    attr(apiLine, "id", `api_${tmpId}`);
    setData(valueAt(find(apiLine, ".icon-collapse"), 0), "api", tmpId);
    text(find(apiLine, ".api_name"), line.apikeyName);
    attr(find(apiLine, ".api_name"), "title", line.apikeyName);
    text(find(apiLine, ".api_creation"), line.createdOn);
    text(find(apiLine, ".api_last_use"), line.lastUsedOn ?? noTimeElapsed);
    attr(
      find(apiLine, ".api_last_use"),
      "title",
      line.lastUsedOn ?? noTimeElapsed,
    );
    text(find(apiLine, ".api_expiration"), line.expiration);
    attr(find(apiLine, ".api-icon-action"), "data-api", `api_${tmpId}`);
    attr(find(apiLine, ".api-icon-action"), "data-pkid", line.authKey);

    attr(apiCollapse, "id", `api_collapse_${tmpId}`);
    removeClass(apiCollapse, "template-api");
    text(find(apiCollapse, ".api_key"), line.authKey);
    attr(find(apiCollapse, ".icon-clone"), "data-copy", line.authKey);
    attr(
      find(apiCollapse, ".icon-clone"),
      "data-success",
      `api_copy_success_${tmpId}`,
    );
    attr(find(apiCollapse, ".api-copy"), "id", `api_copy_success_${tmpId}`);

    if (line.revokedOn === null && !line.isExpired) {
      apiList.appendChild(apiLine);
      apiLine.after(apiCollapse);
    } else {
      show(document.querySelectorAll("#show_expired_list"));
      apiListExpired.appendChild(apiLine);
      apiLine.after(apiCollapse);
      remove(find(apiLine, ".api-icon-action"));
      if (line.isExpired) {
        html(
          find(apiLine, ".api_expiration"),
          `<i class="gallery-icon-skull api-skull"></i> <span>${line.expiredOn}</span>`,
        );
      } else {
        html(
          find(apiLine, ".api_expiration"),
          // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- this branch is `!line.isExpired` inside the outer `else` of `!line.revokedOn && !line.isExpired`, so `line.revokedOn` must be truthy here.
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
  on(iconCollapse, "click", function (this: Element) {
    const apiId = data<string>(this, "api");
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- apiId always comes from a real, currently-rendered row's own data-api attribute.
    const apiCollapse = document.getElementById(`api_collapse_${apiId}`)!;
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- see the identical justification above.
    const apiLine = document.getElementById(`api_${apiId}`)!;

    if (isVisible(apiCollapse)) {
      removeClass(apiCollapse, "open");
      removeClass(apiLine, "open");
      addClass(find(apiLine, ".icon-collapse"), "close");
      apiCollapse.style.display = "none";
      addClass(find(apiCollapse, ".api-copy"), "api-hide");
    } else {
      addClass(apiCollapse, "open");
      addClass(apiLine, "open");
      removeClass(find(apiLine, ".icon-collapse"), "close");
      apiCollapse.style.display = "grid";
    }

    resetSection("apikey-display", false, true);
  });

  const cloneButtons = document.querySelectorAll(
    ".api-tab-collapse .icon-clone",
  );
  off(cloneButtons, "click");
  on(cloneButtons, "click", function (this: Element) {
    const dataToCopy = data<string>(this, "copy");
    const selector = data<string>(this, "success");
    copyToClipboard(dataToCopy, strCopyKeyId, `#${selector}`);
  });

  const editButtons = document.querySelectorAll(".api-tab-line .edit-mode");
  off(editButtons, "click");
  on(editButtons, "click", function (this: Element) {
    const parent = this.parentElement;
    const selector = parent !== null ? data<string>(parent, "api") : "";
    openApiEditModal(`#${selector}`);
  });

  const deleteButtons = document.querySelectorAll(".api-tab-line .delete-mode");
  off(deleteButtons, "click");
  on(deleteButtons, "click", function (this: Element) {
    const parent = this.parentElement;
    const selector = parent !== null ? data<string>(parent, "api") : "";
    openApiRevokeModal(`#${selector}`);
  });
}

function resetSection(selector: string, scroll = true, maxContent = false) {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller passes a real section id.
  const element = document.getElementById(selector)!;
  const scrollH = maxContent
    ? "max-content"
    : String(element.scrollHeight) + "px";
  element.style.maxHeight = scrollH;

  if ("account-display" !== selector && scroll) {
    setTimeout(() => {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- selector is always a real, non-empty section id, so split("-")[0] is always defined; the resulting section id is always a real element.
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
      selectedDate,
    );
    trigger(
      document.querySelectorAll('select[name="api_expiration"]'),
      "change",
    );
    setVal(document.querySelectorAll("#api_expiration_date"), "");

    setVal(document.querySelectorAll("#api_secret_key"), "");
    hide(document.querySelectorAll("#retrieves_keyapi"));
    show(document.querySelectorAll("#generate_keyapi"));
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #done_apikey unconditionally.
    document.querySelector<HTMLButtonElement>("#done_apikey")!.disabled = true;
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
    copyToClipboard(secret, strCopyKeySecret, "#api_key_copy_success");

    const doneButton =
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #done_apikey unconditionally.
      document.querySelector<HTMLButtonElement>("#done_apikey")!;
    doneButton.disabled = false;
    on(doneButton, "click", closeApiModal);
  });

  const apiIdCopy = document.querySelectorAll("#api_id_copy");
  off(apiIdCopy, "click");
  on(apiIdCopy, "click", function () {
    copyToClipboard(id, strCopyKeyId, "#api_id_copy_success");
  });
}

//api edit modal
function openApiEditModal(selector: string) {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller passes a real, currently-rendered row's own selector.
  const target = document.querySelector(selector)!;
  const value = textOf(find(target, ".api_name"));
  const pkid = data<string>(
    valueAt(find(target, ".api-icon-action"), 0),
    "pkid",
  );
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
    const value = val(document.querySelectorAll("#api_key_edit")) ?? "";

    if ("" === value) {
      show(document.querySelectorAll("#error_api_key_edit"));
      return;
    }
    void setInfos(
      {
        pkid,
        key_name: value,
      },
      "pwg.users.api_key.edit",
      // 204 No Content -- sessionApiKeyUpdate's real response has no body.
      (_res: unknown) => {
        pwgToaster({ text: strApiEdited, icon: "success" });
        void getAllApiKeys(true);
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
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller passes a real, currently-rendered row's own selector.
  const target = document.querySelector(selector)!;
  const apiName = textOf(find(target, ".api_name"));
  const pkid = data<string>(
    valueAt(find(target, ".api-icon-action"), 0),
    "pkid",
  );
  const titleText = sprintf(strRevokeKey, apiName);
  text(document.querySelectorAll("#api_modal_revoke_title"), titleText);

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
    void setInfos(
      {
        pkid,
      },
      "pwg.users.api_key.revoke",
      // 204 No Content -- sessionApiKeyRevoke's real response has no body.
      (_res: unknown) => {
        pwgToaster({ text: strApiRevoked, icon: "success" });
        void getAllApiKeys(true);
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
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
  if (window.isSecureContext && navigator.clipboard) {
    void navigator.clipboard.writeText(copy);
    if (selector !== null && selector !== "") {
      removeClass(document.querySelectorAll(selector), "api-hide");
    } else {
      pwgToaster({ text: message, icon: "success" });
    }
    return true;
  } else {
    pwgToaster({ text: strCantCopy, icon: "error" });
    return false;
  }
}

function saveApiKeyEvent() {
  const handler = () => {
    const apiName = val(document.querySelectorAll("#api_key_name")) ?? "";
    let apiDuration: string | number | undefined = val(
      document.querySelectorAll('select[name="api_expiration"]'),
    );

    if (apiName === "") {
      show(document.querySelectorAll("#error_api_key_name"));
      return;
    }

    const expirationDate = val(
      document.querySelectorAll("#api_expiration_date"),
    );
    if (
      "custom" === apiDuration &&
      (expirationDate === undefined || expirationDate === "")
    ) {
      show(document.querySelectorAll("#error_api_key_date"));
      return;
    }

    unbindApiKeyEvents();

    if ("custom" === apiDuration) {
      const today = new Date();
      const customDate = new Date(
        String(val(document.querySelectorAll("#api_expiration_date"))),
      );
      const oneDay = 1000 * 60 * 60 * 24;
      const days = Math.ceil((customDate.getTime() - today.getTime()) / oneDay);
      apiDuration = days;
    } else {
      // Genuine pre-existing bug found only by ESLint's stricter static
      // analysis: `Number(x) ?? 1` can never fall back to 1 -- `Number()`
      // returns `NaN` for invalid input, never `null`/`undefined`, so `??`
      // never triggers. `||` is the operator that actually treats `NaN`
      // (like `0`) as falsy, matching the real intended fallback.
      apiDuration = Number(apiDuration) || 1;
    }

    void setInfos(
      {
        key_name: apiName,
        duration: apiDuration,
      },
      "pwg.users.api_key.create",
      (
        res: operations["sessionApiKeyCreate"]["responses"][201]["content"]["application/json"],
      ) => {
        pwgToaster({ text: strApiAdded, icon: "success" });
        void getAllApiKeys(true);
        successApiModal(res.apikeySecret, res.authKey);
      },
      (_err: unknown) => {
        saveApiKeyEvent();
      },
    );
  };

  on(document.querySelectorAll("#save_apikey"), "click.apikey", handler);
  on(window, "keydown.apikey", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    if ((e as KeyboardEvent).key === "Enter") {
      e.preventDefault();
      handler();
    }
  });
}

function unbindApiKeyEvents() {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- profile.latte renders #api_modal unconditionally.
  const modal = document.getElementById("api_modal")!;
  off([modal, ...Array.from(modal.querySelectorAll("*"))], ".apikey");
  off(window, ".apikey");
}
