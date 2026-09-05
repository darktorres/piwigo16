import type { operations } from "../../../../openapi/client/schema";
// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- tags.latte's own generic `.search-cancel`/`.search-input`
// pair needs the shared wiring; this page used to get it incidentally,
// as a side effect of importing from what was then the same file
// (common.ts); the P51-I split made that dependency explicit instead
// of leaving it accidental.
import "./common";
import {
  jConfirmAlertOptions,
  jConfirmConfirmOptions,
} from "./jconfirmPresets";
import { TemporaryState } from "./TemporaryState";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { cookie, setCookie } from "../../../default/js/vendor/utils/cookie";
import { alert, confirm } from "../../../default/js/vendor/widgets/jconfirm";
import {
  addClass,
  animate,
  attr,
  attrOf,
  css,
  data,
  dataId,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  htmlOf,
  is,
  on,
  parseHtml,
  prepend,
  ready,
  removeClass,
  setChecked,
  setData,
  setVal,
  show,
  slideDown,
  slideUp,
  trigger,
  val,
  valId,
  valueAt,
} from "../../../default/js/vendor/utils/dom";

// Real per-row shape (P47), traced to TagsPageRenderer.php's own
// `$all_tags` construction (`name`/`id`/`url_name`/`raw_name` always
// set; `counter`/`alt_names` only set when non-empty, hence optional).
interface TagRow {
  id: number;
  name: string;
  url_name: string;
  raw_name: string;
  counter?: number;
  alt_names?: string;
}

type TagCreateResponse =
  operations["tagCreate"]["responses"][201]["content"]["application/json"];
type TagRenameResponse =
  operations["tagRename"]["responses"][200]["content"]["application/json"];
type TagDeleteResponse =
  operations["tagDelete"]["responses"][200]["content"]["application/json"];
type TagDuplicateResponse =
  operations["tagDuplicate"]["responses"][201]["content"]["application/json"];
type TagMergeResponse =
  operations["tagMerge"]["responses"][200]["content"]["application/json"];

const pwgToken = pwg_getPageData<string>("csrf_token");
const parsedOrphanTagNames: unknown = JSON.parse(
  pwg_getPageData<string>("orphan_tag_names_array"),
);
const orphanTagNames = Array.isArray(parsedOrphanTagNames)
  ? parsedOrphanTagNames.filter((n): n is string => typeof n === "string")
  : [];
const strDelete = pwg_getPageString('Delete tag "%s"?');
const strDeleteTags = pwg_getPageString("Delete tags {%s}?");
const strYesDeleteConfirmation = pwg_getPageString("Yes, delete");
const strNoDeleteConfirmation = pwg_getPageString("No, I have changed my mind");
const strYesRenameConfirmation = pwg_getPageString("Yes, rename");
const strTagDeleted = pwg_getPageString('Tag "%s" succesfully deleted');
const strTagsDeleted = pwg_getPageString("Tags {%s} succesfully deleted");
const strAlreadyExist = pwg_getPageString('Tag "%s" already exists');
const strTagCreated = pwg_getPageString('Tag "%s" created');
const strTagRename = pwg_getPageString('Rename "%s"');
const strDeleteOrphanTags = pwg_getPageString("Delete orphan tags ?");
const strOrphanTags = pwg_getPageString("You have %s1 orphan : %s2");
const strDeleteThem = pwg_getPageString("Delete them");
const strKeepThem = pwg_getPageString("Keep them");
const strCopy = pwg_getPageString(" (copy)");
const strOtherCopy = pwg_getPageString(" (copy %s)");
const strMergedInto = pwg_getPageString(
  'Tag(s) {%s1} succesfully merged into "%s2"',
);
const strAndOthersTags = pwg_getPageString("and %s others");
const strNumberPhotos = pwg_getPageString("%d photos");
const strNoPhotos = pwg_getPageString("no photo");
const strSelectAllTag = pwg_getPageString("Select all %d tags");
const strClearSelection = pwg_getPageString("Clear Selection");
const strSelectionDone = pwg_getPageString(
  "The %d tags on this page are selected",
);
const strTagSelected = pwg_getPageString("<b>%d</b> tag selected");
const strTagsFound = pwg_getPageString("<b>%d</b> tags found");
const strTagFound = pwg_getPageString("<b>%d</b> tag found");

//Get the data
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
let dataTags = data(
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- tags.latte renders .tag-container unconditionally.
  document.querySelector(".tag-container")!,
  "tags",
) as TagRow[];

//Initiate Select
setChecked(document.querySelectorAll("#select-100"), true);

//Orphan tags
on(document.querySelectorAll(".info-warning p a"), "click", () => {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
  const url = data(
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- this handler is only ever bound to a real, already-clicked .info-warning p a element.
    document.querySelector(".info-warning p a")!,
    "url",
  ) as string;
  const tags = orphanTagNames;
  const strOrphans = strOrphanTags
    .replace("%s1", String(tags.length))
    .replace("%s2", tags.join(", "));
  confirm({
    content: strOrphans,
    title: strDeleteOrphanTags,
    boxWidth: "30%",
    type: "red",
    buttons: {
      delete: {
        text: strDeleteThem,
        btnClass: "btn-red",
        action: function () {
          window.location.href = url.replace(/amp;/g, "");
        },
      },
      keep: {
        text: strKeepThem,
        action: function () {
          hide(document.querySelectorAll(".info-warning"));
        },
      },
    },
  });
});

//Create and recycle tag box
function createTagBox(
  id: number,
  name: string,
  url_name: string,
  count: number | undefined,
  rawNameArg: string | null = null,
): Element {
  const rawName = rawNameArg ?? name;
  const uEdit = "admin.php?page=batch_manager&filter=tag-" + String(id);
  const uView = "index.php?/tags/" + String(id) + "-" + url_name;
  // `name` is a plain string straight off the JSON API response, never
  // percent-encoded -- the legacy `unescape(name)` this replaced was a
  // no-op for almost every real tag name and a real corruption risk for
  // one shaped like a percent-hex escape (e.g. a tag named "tag %41");
  // `decodeURIComponent` isn't a safe substitute either, since it throws
  // on the common case of a bare "%" (e.g. "50% off").
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the .tag-template block always exists in the page markup.
  let markup = htmlOf(document.querySelectorAll(".tag-template"))!
    .replace(/%name%/g, name)
    .replace("%U_VIEW%", uView)
    .replace("%U_EDIT%", uEdit)
    .replace("%raw_name%", rawName);
  if (name === rawName) {
    markup = markup.replace("icon-globe", "");
  }
  const newTag = valueAt(
    parseHtml(
      '<div class="tag-box test" data-id=' +
        String(id) +
        ' data-selected="0">' +
        markup +
        "</div>",
    ),
    0,
  );
  if (is(document.querySelectorAll("#toggleSelectionMode"), ":checked")) {
    addClass(newTag, "selection");
    show(find(newTag, ".in-selection-mode"));
  }
  if (count !== undefined && count > 0) {
    css(
      find(newTag, ".dropdown-option.view, .dropdown-option.manage"),
      "display",
      "block",
    );
    html(
      find(newTag, ".tag-dropdown-header i"),
      strNumberPhotos.replace("%d", String(count)),
    );
  } else {
    html(find(newTag, ".tag-dropdown-header i"), strNoPhotos);
  }
  return newTag;
}

function recycleTagBox(
  tagBox: Element,
  id: number,
  name: string,
  url_name: string,
  count: number | undefined,
  rawNameArg: string | null = null,
): void {
  const rawName = rawNameArg ?? name;
  attr(tagBox, "data-id", String(id));
  html(find(tagBox, ".tag-name, .tag-dropdown-header b"), name);
  setVal(find(tagBox, ".tag-name-editable"), name);
  attr(tagBox, "data-selected", "0");
  find(tagBox, ".tag-name").forEach((el) => {
    setData(el, "rawname", rawName);
  });

  //Dropdown
  const uEdit = "admin.php?page=batch_manager&filter=tag-" + String(id);
  const uView = "index.php?/tags/" + String(id) + "-" + url_name;
  attr(find(tagBox, ".dropdown-option.view"), "href", uView);
  attr(find(tagBox, ".dropdown-option.manage"), "href", uEdit);

  if (count !== undefined && count > 0) {
    css(
      find(tagBox, ".dropdown-option.view, .dropdown-option.manage"),
      "display",
      "block",
    );
    html(
      find(tagBox, ".tag-dropdown-header i"),
      strNumberPhotos.replace("%d", String(count)),
    );
  } else {
    html(find(tagBox, ".tag-dropdown-header i"), strNoPhotos);
  }
}

//Number On Badge
function updateBadge(): void {
  html(document.querySelectorAll(".badge-number"), String(dataTags.length));
  if (dataTags.length === 0) {
    addClass(
      document.querySelectorAll(".tag-header #add-tag .add-tag-label"),
      "highlight",
    );
  } else {
    removeClass(
      document.querySelectorAll(".tag-header #add-tag .add-tag-label"),
      "highlight",
    );
  }
}

// `.add-tag-container` is `display: none` until `.input-mode` is added
// below -- looks unreachable, but isn't: `.add-tag-label` is a real
// <label>, `#add-tag-input` is its implicit associated control (the
// first labelable descendant), and clicking anywhere on the label makes
// the browser dispatch its own activation click at that input, which
// bubbles up through `.add-tag-container` same as a real click would.
// Confirmed live -- a direct click at `.add-tag-container` itself
// (0x0, no hit-testable point) times out, but clicking `.add-tag-label`
// reaches this listener regardless, both before and after this
// conversion.
on(document.querySelectorAll(".add-tag-container"), "click", function () {
  addClass(document.querySelectorAll("#add-tag"), "input-mode");
  document.querySelector<HTMLElement>("#add-tag-input")?.focus();
  hide(document.querySelectorAll(".tag-info"));
});

on(
  document.querySelectorAll("#add-tag .icon-cancel-circled"),
  "click",
  function () {
    removeClass(document.querySelectorAll("#add-tag"), "input-mode");
    hide(document.querySelectorAll(".tag-info"));
  },
);

//Display/Hide tag option
document.querySelectorAll(".tag-box").forEach((tagBox) => {
  setupTagbox(tagBox);
});

//Call the API when rename a tag
on(document.querySelectorAll(".TagSubmit"), "click", function () {
  hide(document.querySelectorAll(".TagSubmit"));
  show(document.querySelectorAll(".TagLoading"));
  // Non-null: setUpPopin() always sets this id before the form is
  // submittable.
  const $tagboxid = Number(
    attrOf(
      find(
        document.querySelectorAll(".RenameTagPopInContainer"),
        ".tag-property-input",
      ),
      "id",
    ),
  );
  renameTag(
    $tagboxid,
    String(
      val(
        find(
          document.querySelectorAll(".RenameTagPopInContainer"),
          ".tag-property-input",
        ),
      ),
    ),
  )
    .then(() => {
      show(document.querySelectorAll(".TagSubmit"));
      hide(document.querySelectorAll(".TagLoading"));
      renameTagClose();
      cleanCheckmark();
      const changedBox = document.querySelector(
        '[data-id="' + String($tagboxid) + '"]',
      );
      if (changedBox !== null) {
        wrapWithDiv(changedBox, "tag-changed");
      }
      prepend(
        document.querySelectorAll(".tag-changed"),
        '<i class="icon-ok tag-checkmark"></i>',
      );
    })
    .catch((message: unknown) => {
      show(document.querySelectorAll(".TagSubmit"));
      hide(document.querySelectorAll(".TagLoading"));
      console.error(message);
    });
});

// `.wrap(html)` -- jQuery inserts a copy of `html` immediately before the
// matched element, then moves the element inside it. There's exactly one
// element here, so no cloning is needed.
function wrapWithDiv(el: Element, className: string): void {
  const wrapper = document.createElement("div");
  wrapper.className = className;
  el.parentElement?.insertBefore(wrapper, el);
  wrapper.appendChild(el);
}

function cleanCheckmark(): void {
  // `.unwrap()` -- removes the parent, keeping the child in its place.
  document.querySelectorAll(".tag-changed > *").forEach((child) => {
    child.parentElement?.replaceWith(child);
  });
  document.querySelectorAll(".tag-checkmark").forEach((el) => {
    el.remove();
  });
}

/*-------
 Add a tag
-------*/

on(document.querySelectorAll("#add-tag"), "submit", function (e: Event) {
  e.preventDefault();
  if (val(document.querySelectorAll("#add-tag-input")) !== "") {
    const loadState = new TemporaryState();
    loadState.removeClass(
      document.querySelectorAll("#add-tag .icon-validate"),
      "icon-plus",
    );
    loadState.changeHTML(
      document.querySelectorAll("#add-tag .icon-validate"),
      "<i class='icon-spin6 animate-spin'> </i>",
    );
    loadState.changeAttribute(
      document.querySelectorAll("#add-tag .icon-validate"),
      "style",
      "pointer-event:none",
    );
    addTag(String(val(document.querySelectorAll("#add-tag-input"))))
      .then(function () {
        showMessage(
          strTagCreated.replace(
            "%s",
            String(val(document.querySelectorAll("#add-tag-input"))),
          ),
        );
        setVal(document.querySelectorAll("#add-tag-input"), "");
        removeClass(document.querySelectorAll("#add-tag"), "input-mode");
        // The search input's own "input" handler (below in this same
        // file) is a real native `on()` registration (P49-C) -- a plain
        // native dispatch reaches it the same as any other real "input"
        // event.
        trigger(
          document.querySelectorAll("#search-tag .search-input"),
          "input",
        );
        loadState.reverse();
      })
      .catch((message: unknown) => {
        loadState.reverse();
        showError(message instanceof Error ? message.message : String(message));
      });
  }
});

on(document.querySelectorAll("#add-tag .icon-validate"), "click", function () {
  const form = document.querySelector<HTMLFormElement>("#add-tag");
  // Not `.trigger("submit")`/a bare `.submit()` call: `HTMLFormElement
  // .submit()` deliberately does NOT fire a "submit" event (it bypasses
  // validation and any listener by spec), so it would silently never
  // reach the native "submit" listener registered above.
  // `requestSubmit()` is the real native equivalent of "submit this
  // form as if the user had" -- it does dispatch the event.
  if (form !== null && hasClass(form, "input-mode")) {
    form.requestSubmit();
  }
});

async function addTag(name: string): Promise<void> {
  let response: TagCreateResponse;
  try {
    response = await ajax<TagCreateResponse>({
      url: "api/v1/tags",
      type: "POST",
      json: {
        name: name,
      },
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      dataType: "json",
    });
  } catch (err) {
    if (err instanceof AjaxError && err.status === 422) {
      throw new Error(strAlreadyExist.replace("%s", name), { cause: err });
    }
    throw new Error(err instanceof AjaxError ? err.statusText : String(err), {
      cause: err,
    });
  }

  const newTag = createTagBox(response.id, response.name, response.urlName, 0);
  document.querySelector(".tag-container")?.prepend(newTag);
  setupTagbox(newTag);
  updateSearchInfo();

  //Update the local tag list
  dataTags.unshift({
    name: response.name,
    raw_name: response.name,
    id: response.id,
    url_name: response.urlName,
  });
  updateBadge();
}
/*-------
 Setup Tag Box
-------*/

function setupTagbox(tagBox: Element): void {
  //Dropdown options
  on(find(tagBox, ".showOptions"), "click", function () {
    css(find(tagBox, ".tag-dropdown-block"), "display", "grid");
  });

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let optionIsClicked = false;
    find(tagBox, ".dropdown-option").forEach((option) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
      if (option.contains(e.target as Node | null)) {
        optionIsClicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: optionIsClicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!optionIsClicked) {
      hide(find(tagBox, ".tag-dropdown-block"));
    }
  });

  // Selection behaviour
  on(tagBox, "click", function () {
    if (hasClass(document.querySelectorAll(".tag-container"), "selection")) {
      if (attrOf(tagBox, "data-selected") === "1") {
        attr(tagBox, "data-selected", "0");
        removeSelectedItem(Number(attrOf(tagBox, "data-id")));
      } else {
        attr(tagBox, "data-selected", "1");
        addSelectedItem(Number(attrOf(tagBox, "data-id")));
      }
      updateSelectionContent();
    }
  });

  //Edit Name
  on(find(tagBox, ".dropdown-option.edit"), "click", function () {
    const id = dataId(tagBox, "id");
    const tagIndex = dataTags.findIndex((tag) => tag.id === id);
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always comes from a real tag box, which was itself rendered from this same dataTags array.
    const tagRawName = dataTags[tagIndex]!.raw_name;
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- see the identical justification above.
    const tagName = dataTags[tagIndex]!.name;
    setUpPopin(id, tagRawName, tagName);
    renameTagOpen();
  });

  //Delete Tag
  on(find(tagBox, ".dropdown-option.delete"), "click", function () {
    confirm({
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real tagBox has a real .tag-name element.
      title: strDelete.replace("%s", htmlOf(find(tagBox, ".tag-name"))!),
      buttons: {
        confirm: {
          text: strYesDeleteConfirmation,
          btnClass: "btn-red",
          action: function () {
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real tagBox has a real .tag-name element.
            removeTag(dataId(tagBox, "id"), htmlOf(find(tagBox, ".tag-name"))!);
          },
        },
        cancel: {
          text: strNoDeleteConfirmation,
        },
      },
      ...jConfirmConfirmOptions,
    });
  });

  //Duplicate Tag
  on(find(tagBox, ".dropdown-option.duplicate"), "click", function () {
    void duplicateTag(
      dataId(tagBox, "id"),
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      data(valueAt(find(tagBox, ".tag-name"), 0), "rawname") as string,
    ).then((newTag) => {
      showMessage(strTagCreated.replace("%s", newTag.name));
    });
  });
}

function setUpPopin(id: number, tagRawName: string, tagName: string): void {
  attr(
    find(
      document.querySelectorAll(".RenameTagPopInContainer"),
      ".tag-property-input",
    ),
    "id",
    String(id),
  );

  html(
    document.querySelectorAll(".AddIconTitle span"),
    strTagRename.replace("%s", tagName),
  );
  on(
    document.querySelectorAll(".ClosePopIn, .TagCancel"),
    "click",
    function () {
      renameTagClose();
    },
  );
  html(document.querySelectorAll(".TagSubmit"), strYesRenameConfirmation);
  setVal(
    find(
      document.querySelectorAll(".RenameTagPopInContainer"),
      ".tag-property-input",
    ),
    tagRawName,
  );
}

function renameTagClose(): void {
  fadeOut(document.querySelectorAll("#RenameTag"));
}

function renameTagOpen(): void {
  fadeIn(document.querySelectorAll("#RenameTag"));
  document.querySelector<HTMLElement>(".tag-property-input")?.focus();
}

function removeTag(id: number, name: string): void {
  alert({
    title: strTagDeleted.replace("%s", name),
    // eslint-disable-next-line @typescript-eslint/promise-function-async -- must return ajax()'s own AjaxThenable (jconfirm.ts's `isThenable()` checks for its real `.always()`); `async` would re-wrap it through `Promise.resolve()` and lose that method.
    content: function () {
      return ajax({
        url: "api/v1/tags/" + String(id),
        type: "DELETE",
        headers: {
          "X-CSRF-Token": pwgToken,
        },
        dataType: "json",
        success: function (_data: TagDeleteResponse) {
          document
            .querySelector('.tag-box[data-id="' + String(id) + '"]')
            ?.remove();
          //Update data
          dataTags = dataTags.filter((tag) => tag.id !== id);
          showMessage(strTagDeleted.replace("%s", name));
          updateBadge();
          updateSearchInfo();
          updatePaginationMenu();
        },
        error: function () {
          showError("A problem has occured");
        },
      });
    },
    ...jConfirmAlertOptions,
  });
}

async function renameTag(
  id: number,
  new_name: string,
): Promise<TagRenameResponse> {
  let response: TagRenameResponse;
  try {
    response = await ajax<TagRenameResponse>({
      url: "api/v1/tags/" + String(id),
      type: "PATCH",
      json: {
        name: new_name,
      },
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      dataType: "json",
    });
  } catch (err) {
    if (err instanceof AjaxError && err.status === 422) {
      throw new Error(strAlreadyExist.replace("%s", new_name), {
        cause: err,
      });
    }
    throw new Error(err instanceof AjaxError ? err.statusText : String(err), {
      cause: err,
    });
  }

  html(
    document.querySelectorAll(
      '.tag-box[data-id="' +
        String(id) +
        '"] p, .tag-box[data-id="' +
        String(id) +
        '"] .tag-dropdown-header b',
    ),
    response.name,
  );
  attr(
    document.querySelectorAll(
      '.tag-box[data-id="' + String(id) + '"] .tag-name-editable',
    ),
    "value",
    response.name,
  );
  attr(
    document.querySelectorAll(
      '.tag-box[data-id="' + String(id) + '"] .tag-name',
    ),
    "data-rawname",
    response.nameRaw,
  );
  const uView = "index.php?/tags/" + String(id) + "-" + response.urlName;
  attr(document.querySelectorAll(".dropdown-option.view"), "href", uView);

  //Update the local tag list
  const index = dataTags.findIndex((tag) => tag.id === id);
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always identifies a real, currently-rendered tag box, which was itself rendered from this same array.
  dataTags[index]!.name = response.name;
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- see the identical justification above.
  dataTags[index]!.raw_name = response.nameRaw;
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- see the identical justification above.
  dataTags[index]!.url_name = response.urlName;

  return response;
}

async function duplicateTag(
  id: number,
  name: string,
): Promise<TagDuplicateResponse> {
  let copyName = name + strCopy;

  const nameExist = function (candidateName: string): boolean {
    let exist = false;
    document.querySelectorAll(".tag-box .tag-name").forEach((el) => {
      if (htmlOf(el) === candidateName) exist = true;
    });
    return exist;
  };

  let i = 1;
  while (nameExist(copyName)) {
    copyName = name + strOtherCopy.replace("%s", String(i++));
  }

  let response: TagDuplicateResponse;
  try {
    response = await ajax<TagDuplicateResponse>({
      url: "api/v1/tags/" + String(id) + "/actions/duplicate",
      type: "POST",
      json: {
        name: copyName,
      },
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      dataType: "json",
    });
  } catch (err) {
    throw new Error(err instanceof AjaxError ? err.statusText : String(err), {
      cause: err,
    });
  }

  const newTag = createTagBox(
    response.id,
    response.name,
    response.urlName,
    response.count,
  );
  document
    .querySelector('.tag-box[data-id="' + String(id) + '"]')
    ?.after(newTag);
  setupTagbox(newTag);

  //Update Data
  const index = dataTags.findIndex((tag) => tag.id === id);
  dataTags.splice(index + 1, 0, {
    name: response.name,
    // Was missing entirely -- `TagRow.raw_name` is a required
    // field, and `tagDuplicate`'s own response has no separate
    // raw-name field to source it from (same gap `tagCreate`'s
    // response has, worked around identically in addTag()'s own
    // success handler above: the rendered `name` is the best
    // available stand-in until a real page reload re-fetches the
    // true raw name).
    raw_name: response.name,
    id: response.id,
    url_name: response.urlName,
    counter: response.count,
  });
  updateBadge();
  updateSearchInfo();

  return response;
}

/*-------
 Selection mode
-------*/
let selected: number[] = [];
const maxItemDisplayed = 5;

setChecked(document.querySelectorAll("#toggleSelectionMode"), false);
on(document.querySelectorAll("#toggleSelectionMode"), "click", function () {
  if (is(document.querySelectorAll("#toggleSelectionMode"), ":checked")) {
    enterSelectionMode();
  } else {
    exitSelectionMode();
  }
  hide(document.querySelectorAll(".tag-info"));
});

function enterSelectionMode(): void {
  addClass(document.querySelectorAll(".in-selection-mode"), "show");
  addClass(document.querySelectorAll(".not-in-selection-mode"), "hide");
  addClass(document.querySelectorAll(".tag-container"), "selection");
  removeClass(document.querySelectorAll(".tag-box"), "edit-name");
}

function exitSelectionMode(): void {
  removeClass(document.querySelectorAll(".in-selection-mode"), "show");
  removeClass(document.querySelectorAll(".not-in-selection-mode"), "hide");
  removeClass(document.querySelectorAll(".tag-container"), "selection");
  attr(document.querySelectorAll(".tag-box"), "data-selected", "0");
  slideUp(document.querySelectorAll(".tag-select-message"));
  clearSelection();
}

function clearSelection(): void {
  selected = [];
  html(document.querySelectorAll(".selection-mode-tag .tag-list"), "");
  hide(document.querySelectorAll(".selection-other-tags"));
  updateSelectionContent();
}

function addSelectedItem(id: number): void {
  if (!selected.includes(id)) {
    selected.push(id);

    if (selected.length > maxItemDisplayed) {
      show(document.querySelectorAll(".selection-other-tags"));
      const numberDisplayed = document.querySelectorAll(
        ".selection-mode-tag .tag-list div",
      ).length;
      html(
        document.querySelectorAll(".selection-other-tags"),
        strAndOthersTags.replace(
          "%s",
          String(selected.length - numberDisplayed),
        ),
      );
    } else {
      hide(document.querySelectorAll(".selection-other-tags"));
      if (dataTags.findIndex((tag) => tag.id === id) > -1) {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the findIndex() check above already confirms find() succeeds here.
        createSelectionItem(id, dataTags.find((tag) => tag.id === id)!.name);
      }
    }
  }
}

function createSelectionItem(id: number, name: string): void {
  const newItemStructure = valueAt(
    parseHtml(
      '<div data-id="' +
        String(id) +
        '"><a class="icon-cancel"></a><p>' +
        name +
        "</p> </div>",
    ),
    0,
  );
  document
    .querySelector(".selection-mode-tag .tag-list")
    ?.prepend(newItemStructure);
  on(
    document.querySelectorAll(
      '.selection-mode-tag .tag-list div[data-id="' + String(id) + '"] a',
    ),
    "click",
    function () {
      removeSelectedItem(id);
    },
  );
}

/**
 * After removing a displayed selection item, the selection-mode tag list
 * shows one fewer item than `maxItemDisplayed` -- backfill with the
 * first still-selected tag not already shown.
 */
function backfillSelectionItem(): void {
  for (const currentId of selected) {
    const alreadyShown =
      document.querySelectorAll(
        '.selection-mode-tag .tag-list div[data-id="' +
          String(currentId) +
          '"]',
      ).length !== 0;
    if (!alreadyShown) {
      const indexOfTag = dataTags.findIndex((tag) => tag.id === currentId);
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- currentId always comes from the `selected` array, always a real tag id present in dataTags.
      createSelectionItem(currentId, dataTags[indexOfTag]!.name);
      return;
    }
  }
}

function removeSelectedItem(id: number): void {
  if (selected.includes(id)) {
    selected = selected.filter((tag) => tag !== id);

    attr(
      document.querySelectorAll('.tag-box[data-id="' + String(id) + '"]'),
      "data-selected",
      "0",
    );
    if (
      document.querySelectorAll(
        '.selection-mode-tag .tag-list div[data-id="' + String(id) + '"]',
      ).length !== 0
    ) {
      document
        .querySelectorAll(
          '.selection-mode-tag .tag-list div[data-id="' + String(id) + '"]',
        )
        .forEach((el) => {
          el.remove();
        });

      if (selected.length >= maxItemDisplayed) {
        backfillSelectionItem();
      }
    }

    const numberDisplayed = document.querySelectorAll(
      ".selection-mode-tag .tag-list div",
    ).length;
    html(
      document.querySelectorAll(".selection-other-tags"),
      strAndOthersTags.replace("%s", String(selected.length - numberDisplayed)),
    );
    if (selected.length - numberDisplayed <= 0) {
      hide(document.querySelectorAll(".selection-other-tags"));
    }

    //Remove the selection message
    slideUp(document.querySelectorAll(".tag-select-message"));
  }
}

function updateMergeItems(): void {
  html(document.querySelectorAll("#MergeOptionsChoices"), "");
  const select = document.querySelector("#MergeOptionsChoices");
  selected.forEach((id) => {
    select?.appendChild(
      valueAt(
        parseHtml(
          '<option value="' +
            String(id) +
            '">' +
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always comes from the `selected` array, always a real tag id present in dataTags.
            dataTags.find((tag) => tag.id === id)!.name +
            "</option>",
        ),
        0,
      ),
    );
  });
}

let mergeOption = false;

function updateSelectionContent(): void {
  const number = selected.length;
  if (number === 0) {
    mergeOption = false;
    show(document.querySelectorAll("#nothing-selected"));
    hide(document.querySelectorAll(".selection-mode-tag"));
    hide(document.querySelectorAll("#MergeOptionsBlock"));
  } else if (number === 1) {
    mergeOption = false;
    hide(document.querySelectorAll("#nothing-selected"));
    show(document.querySelectorAll(".selection-mode-tag"));
    hide(document.querySelectorAll("#MergeOptionsBlock"));
    addClass(document.querySelectorAll("#MergeSelectionMode"), "unavailable");
  } else if (number > 1) {
    hide(document.querySelectorAll("#nothing-selected"));
    removeClass(
      document.querySelectorAll("#MergeSelectionMode"),
      "unavailable",
    );
    if (mergeOption) {
      show(document.querySelectorAll("#MergeOptionsBlock"));
      hide(document.querySelectorAll(".selection-mode-tag"));
      updateMergeItems();
    } else {
      hide(document.querySelectorAll("#MergeOptionsBlock"));
      show(document.querySelectorAll(".selection-mode-tag"));
    }
  }
}

on(document.querySelectorAll("#MergeSelectionMode"), "click", function () {
  mergeOption = true;
  updateSelectionContent();
});

on(document.querySelectorAll("#CancelMerge"), "click", function () {
  mergeOption = false;
  updateSelectionContent();
});

function onAllTagsSelected(): void {
  updateSelectionContent();
  showSelectMessage(
    strTagSelected.replace(/%d/g, String(selected.length)),
    strClearSelection,
    function () {
      selectNone();
      slideUp(document.querySelectorAll(".tag-select-message"));
    },
  );
}

on(document.querySelectorAll("#selectAll"), "click", function () {
  void selectAll(tagToDisplay());
  updateSelectionContent();
  if (selected.length < dataTags.length) {
    showSelectMessage(
      strSelectionDone.replace(
        "%d",
        String(document.querySelectorAll(".tag-box").length),
      ),
      strSelectAllTag.replace("%d", String(dataTags.length)),
      function () {
        html(document.querySelectorAll(".tag-select-message a"), "");
        html(
          document.querySelectorAll(".tag-select-message div"),
          "<i class='icon-spin6 animate-spin'> </i>",
        );
        setTimeout(() => {
          void selectAll(dataTags).then(onAllTagsSelected);
        }, 5);
      },
    );
  }
});

async function selectAll(tags: TagRow[]) {
  const promises: Promise<void>[] = [];
  tags.forEach((tag) => {
    promises.push(
      new Promise<void>((res, _rej) => {
        attr(
          document.querySelectorAll(
            '.tag-box[data-id="' + String(tag.id) + '"]',
          ),
          "data-selected",
          "1",
        );
        addSelectedItem(tag.id);
        res();
      }),
    );
  });
  return Promise.all(promises);
}

function showSelectMessage(
  str1: string,
  str2: string,
  callback: () => void,
): void {
  if (!is(document.querySelectorAll(".tag-select-message"), ":visible")) {
    const message = document.querySelectorAll(".tag-select-message");
    slideDown(message);
    css(message, "display", "flex");
  }

  html(document.querySelectorAll(".tag-select-message div"), str1);
  html(document.querySelectorAll(".tag-select-message a"), str2);
  // `.off("click")` before re-binding: this runs once per select-all
  // round trip, and a plain `on()` would stack a new listener each time
  // rather than replacing the previous one the way jQuery's `.off()` +
  // `.on()` pair did. The click target is rebuilt (its HTML is replaced
  // just above), so the listener is attached to a fresh element each
  // time regardless.
  on(document.querySelectorAll(".tag-select-message a"), "click", callback);
}

on(document.querySelectorAll("#selectNone"), "click", function () {
  slideUp(document.querySelectorAll(".tag-select-message"));
  selectNone();
});

function selectNone(): void {
  attr(document.querySelectorAll(".tag-box"), "data-selected", "0");
  clearSelection();
}

on(document.querySelectorAll("#selectInvert"), "click", function () {
  slideUp(document.querySelectorAll(".tag-select-message"));
  selectInvert(tagToDisplay());
});

function selectInvert(tags: TagRow[]): void {
  tags.forEach((tag) => {
    const tagBox = document.querySelectorAll(
      '.tag-box[data-id="' + String(tag.id) + '"]',
    );
    if (attrOf(tagBox, "data-selected") === "1") {
      attr(tagBox, "data-selected", "0");
      removeSelectedItem(tag.id);
    } else {
      attr(tagBox, "data-selected", "1");
      addSelectedItem(tag.id);
    }
  });
  updateSelectionContent();
}

/*-------
 Actions in selection mode
-------*/

//Remove tags
on(document.querySelectorAll("#DeleteSelectionMode"), "click", function () {
  const names: string[] = [];
  selected.forEach(function (id) {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always comes from the `selected` array, always a real tag id present in dataTags.
    names.push(dataTags.find((tag) => tag.id === id)!.name);
  });

  confirm({
    title: strDeleteTags.replace("%s", tagListToString(names)),
    buttons: {
      confirm: {
        text: strYesDeleteConfirmation,
        btnClass: "btn-red",
        action: function () {
          removeSelectedTags();
        },
      },
      cancel: {
        text: strNoDeleteConfirmation,
      },
    },
    ...jConfirmConfirmOptions,
  });
});

function removeSelectedTags(): void {
  const names: string[] = [];
  selected.forEach(function (id) {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always comes from the `selected` array, always a real tag id present in dataTags.
    names.push(dataTags.find((tag) => tag.id === id)!.name);
  });

  alert({
    title: strTagsDeleted.replace("%s", tagListToString(names)),
    content: async function () {
      // No bulk-delete endpoint (a REST single-resource DELETE per tag,
      // per P27's own design) -- fire one DELETE per selected tag.
      await Promise.all(
        selected.map(async function (id) {
          return ajax({
            url: "api/v1/tags/" + String(id),
            type: "DELETE",
            headers: {
              "X-CSRF-Token": pwgToken,
            },
            dataType: "json",
          });
        }),
      );

      selected.forEach(function (id) {
        document
          .querySelector('.tag-box[data-id="' + String(id) + '"]')
          ?.remove();
      });

      // Update Data
      dataTags = dataTags.filter((tag) => !selected.includes(tag.id));

      clearSelection();
      updatePaginationMenu();
      updateBadge();
      updateSearchInfo();
    },
    ...jConfirmAlertOptions,
  });
}

//Merge Tags
on(document.querySelectorAll(".ConfirmMergeButton"), "click", () => {
  // Single-value <select>, never multi. `valId()` returns null only when
  // nothing is selected, which can't happen here -- the merge button is
  // only reachable once 2+ tags are already selected, and each one adds a
  // real <option> to this same <select> (see updateMergeItems() above).
  const destId = valId(document.querySelectorAll("#MergeOptionsChoices"));
  if (destId === null) {
    return;
  }
  mergeGroups(destId, selected);
});

function mergeGroups(destination_id: number, merge_ids: number[]): void {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- destination_id always comes from #MergeOptionsChoices, whose own options are only ever real tag ids (updateMergeItems()).
  const destinationName = htmlOf(
    document.querySelectorAll(
      '.tag-box[data-id="' + String(destination_id) + '"] .tag-name',
    ),
  )!;
  const mergeName: string[] = [];

  merge_ids.forEach((id) => {
    mergeName.push(
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- id always comes from the `selected` array, always a real tag box.
      htmlOf(
        document.querySelectorAll(
          '.tag-box[data-id="' + String(id) + '"] .tag-name',
        ),
      )!,
    );
  });

  const strMessage = strMergedInto
    .replace("%s1", tagListToString(mergeName))
    .replace("%s2", destinationName);

  alert({
    title: strMessage,
    // eslint-disable-next-line @typescript-eslint/promise-function-async -- must return ajax()'s own AjaxThenable (jconfirm.ts's `isThenable()` checks for its real `.always()`); `async` would re-wrap it through `Promise.resolve()` and lose that method.
    content: function () {
      return ajax({
        url: "api/v1/tags/actions/merge",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": pwgToken,
        },
        data: JSON.stringify({
          destinationTagId: destination_id,
          mergeTagIds: merge_ids,
        }),
        dataType: "json",
        success: function (response: TagMergeResponse) {
          const removedIds = response.deletedTagIds.filter(
            (id) => response.destinationTagId !== id,
          );
          for (const id of removedIds) {
            document
              .querySelector('.tag-box[data-id="' + String(id) + '"]')
              ?.remove();
          }
          // Update data
          dataTags = dataTags.filter((tag) => !removedIds.includes(tag.id));
          if (response.imagesInMergedTag.length > 0) {
            const tagBox = document.querySelectorAll(
              '.tag-box[data-id="' + String(response.destinationTagId) + '"]',
            );
            show(
              find(
                tagBox,
                ".dropdown-option.view,.dropdown-option.manage,.tag-dropdown-header i",
              ),
            );
            html(
              document.querySelectorAll(".tag-dropdown-header i"),
              strNumberPhotos.replace(
                "%d",
                String(response.imagesInMergedTag.length),
              ),
            );

            // Update data
            const index = dataTags.findIndex(
              (tag) => tag.id === response.destinationTagId,
            );
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- response.destinationTagId always identifies a real, currently-rendered tag, which was itself rendered from this same array.
            dataTags[index]!.counter = response.imagesInMergedTag.length;
          }
          attr(document.querySelectorAll(".tag-box"), "data-selected", "0");
          clearSelection();
          updatePaginationMenu();
          updateBadge();
          updateSearchInfo();
        },
      });
    },
    ...jConfirmAlertOptions,
  });
}

function tagListToString(list: string[]): string {
  if (list.length > 5) {
    return (
      list.slice(0, 5).join(", ") +
      " " +
      strAndOthersTags.replace("%s", String(list.length - 5))
    );
  } else {
    return list.join(", ");
  }
}

/*-------
 Filter research
-------*/

// `ReturnType<typeof setTimeout>`, not `number` -- this project's
// tsconfig `types` array includes `"node"`, so the ambient
// `setTimeout`/`clearTimeout` here resolve to Node's own
// `NodeJS.Timeout`-returning signatures, not the DOM lib's.
let searchTimeOut: ReturnType<typeof setTimeout> | undefined;
const delaySearchInput = 300;
let actualPage = 1;

on(
  document.querySelectorAll("#search-tag .search-input"),
  "input",
  function () {
    actualPage = 1;

    clearTimeout(searchTimeOut);
    searchTimeOut = setTimeout(() => {
      updatePaginationMenu();
      if (dataTags.filter(isDataSearched).length === 0) {
        show(document.querySelectorAll(".emptyResearch"));
      } else {
        hide(document.querySelectorAll(".emptyResearch"));
      }
    }, delaySearchInput);
  },
);

// Real callers: 4 real `.filter(isDataSearched)` call sites in this
// file -- a prior version of this comment claimed this was dead code
// (the same stale claim corrected in albums.ts's own getId()); wrong
// here too, corrected.
function isDataSearched(tagObj: TagRow): boolean {
  const name = tagObj.raw_name.toLowerCase();
  const stringSearch = String(
    val(document.querySelectorAll("#search-tag .search-input")),
  );
  return name.includes(stringSearch.toLowerCase());
}

/*-------
 Show Info
-------*/
function showError(message: string): void {
  html(document.querySelectorAll(".info-error p"), message);
  attr(document.querySelectorAll(".info-error"), "title", message);
  hide(document.querySelectorAll(".info-info"));
  css(document.querySelectorAll(".info-error"), "display", "flex");
}

function showMessage(message: string): void {
  html(document.querySelectorAll(".info-message p"), message);
  attr(document.querySelectorAll(".info-message"), "title", message);
  hide(document.querySelectorAll(".info-info"));
  css(document.querySelectorAll(".info-message"), "display", "flex");
}

/*-------
 Pagination
-------*/
// eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- tags.latte renders .tag-container unconditionally.
let perPage = dataId(document.querySelector(".tag-container")!, "per_page");
const pageItem = '<a data-page="%d">%d</a>';
const pageEllipsis = "<span>...</span>";
let promisePending = false;
let updateAsk = false;

//Avoid 2 update at the same time
function askUpdatePage(): void {
  if (!promisePending) {
    promisePending = true;
    void updatePage().then(promiseFinish);
  } else {
    updateAsk = true;
  }
}

function promiseFinish(): void {
  promisePending = false;
  if (updateAsk) {
    updateAsk = false;
    askUpdatePage();
  }
}

function updatePaginationMenu(): void {
  html(document.querySelectorAll(".pagination-item-container"), "");

  actualPage = Math.min(actualPage, getNumberPages());

  if (getNumberPages() > 1) {
    show(document.querySelectorAll(".pagination-container"));
    createPaginationMenu();
  } else {
    hide(document.querySelectorAll(".pagination-container"));
  }

  updateArrows();
  askUpdatePage();

  //Remove the selection message
  slideUp(document.querySelectorAll(".tag-select-message"));
}

function createPaginationMenu(): void {
  const nbPage = getNumberPages();

  appendPaginationItem(1);

  if (actualPage > 2) {
    appendPaginationItem();
  }

  if (actualPage !== 1 && actualPage !== nbPage) {
    appendPaginationItem(actualPage);
  }

  if (actualPage < nbPage - 1) {
    appendPaginationItem();
  }

  appendPaginationItem(nbPage);
}

function appendPaginationItem(page: number | null = null): void {
  const container = document.querySelector(".pagination-item-container");
  if (container === null) {
    return;
  }
  if (page != null) {
    const newTag = valueAt(parseHtml(pageItem.replace(/%d/g, String(page))), 0);
    container.appendChild(newTag);
    if (actualPage === page) {
      addClass(newTag, "actual");
    }
    on(newTag, "click", () => {
      actualPage = dataId(newTag, "page");
      updatePaginationMenu();
    });
  } else {
    container.appendChild(valueAt(parseHtml(pageEllipsis), 0));
  }
}

function updateArrows(): void {
  if (actualPage === 1) {
    addClass(
      document.querySelectorAll(".pagination-arrow.left"),
      "unavailable",
    );
  } else {
    removeClass(
      document.querySelectorAll(".pagination-arrow.left"),
      "unavailable",
    );
  }

  if (actualPage === getNumberPages()) {
    addClass(
      document.querySelectorAll(".pagination-arrow.rigth"),
      "unavailable",
    );
  } else {
    removeClass(
      document.querySelectorAll(".pagination-arrow.rigth"),
      "unavailable",
    );
  }
}

function getNumberPages(): number {
  const dataVisible = dataTags.filter(isDataSearched).length;
  return Math.floor((dataVisible - 1) / perPage) + 1;
}

function movePage(toRigth = true): void {
  removeClass(document.querySelectorAll(".tag-box"), "edit-name");
  if (toRigth) {
    if (actualPage < getNumberPages()) {
      actualPage++;
      updatePaginationMenu();
    }
  } else {
    if (actualPage > 1) {
      actualPage--;
      updatePaginationMenu();
    }
  }
}

// `.animate({opacity}, duration).promise().then(next)` waits for every
// matched element's own animation to finish before continuing -- dom.ts's
// `animate()` runs its `complete` callback once per element, not once for
// the whole set, so the aggregation has to happen here.
async function fadeOpacity(
  elements: Element[],
  to: number,
  duration: number,
): Promise<void> {
  return new Promise((resolve) => {
    if (elements.length === 0) {
      resolve();
      return;
    }
    let remaining = elements.length;
    elements.forEach((el) => {
      animate(el, { opacity: to }, duration, () => {
        remaining -= 1;
        if (remaining === 0) {
          resolve();
        }
      });
    });
  });
}

function recycleOrCreateTagBoxes(
  dataToDisplay: TagRow[],
  tagBoxes: Element[],
): void {
  const boxToRecycle = Math.min(dataToDisplay.length, tagBoxes.length);

  for (const [i, tag] of dataToDisplay.slice(0, boxToRecycle).entries()) {
    recycleTagBox(
      valueAt(tagBoxes, i),
      tag.id,
      tag.name,
      tag.url_name,
      tag.counter,
      tag.raw_name,
    );
  }

  if (dataToDisplay.length < tagBoxes.length) {
    tagBoxes.slice(boxToRecycle).forEach((el) => {
      el.remove();
    });
  } else if (dataToDisplay.length > tagBoxes.length) {
    dataToDisplay.slice(boxToRecycle).forEach((tag) => {
      const newTag = createTagBox(
        tag.id,
        tag.name,
        tag.url_name,
        tag.counter,
        tag.raw_name,
      );
      css(newTag, "opacity", 0);
      document.querySelector(".tag-container")?.appendChild(newTag);
      setupTagbox(newTag);
    });
  }

  //Select selected tags
  selected.forEach((id) => {
    attr(
      document.querySelectorAll('.tag-box[data-id="' + String(id) + '"]'),
      "data-selected",
      "1",
    );
  });
}

// Was a manual `new Promise((resolve) => {...})` wrapping purely
// synchronous work (real async boundary is only `fadeOpacity()` below)
// -- flattened to plain async/await, which also resolves this
// function's own real nesting depth (sonarjs/no-nested-functions).
async function updatePage(): Promise<void> {
  const dataToDisplay = tagToDisplay();
  const tagBoxes = Array.from(document.querySelectorAll(".tag-box"));
  cleanCheckmark();
  fadeIn(document.querySelectorAll(".pageLoad"));

  await fadeOpacity(tagBoxes, 0, 500);

  recycleOrCreateTagBoxes(dataToDisplay, tagBoxes);

  fadeOut(document.querySelectorAll(".pageLoad"));
  animate(document.querySelectorAll(".tag-box"), { opacity: 1 }, 500);
  if (getNumberPages() > 1) {
    animate(document.querySelectorAll(".tag-pagination"), { opacity: 1 }, 500);
  }
  updateSearchInfo();
}

function tagToDisplay(): TagRow[] {
  return dataTags
    .filter(isDataSearched)
    .slice((actualPage - 1) * perPage, actualPage * perPage);
}

on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
  movePage();
});

on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
  movePage(false);
});

if (getNumberPages() > 1) {
  show(document.querySelectorAll(".pagination-container"));
  createPaginationMenu();
  updateArrows();
} else {
  hide(document.querySelectorAll(".pagination-container"));
}

on(
  document.querySelectorAll(".pagination-per-page a"),
  "click",
  function (this: Element) {
    perPage = parseInt(htmlOf(this) ?? "");
    updatePaginationMenu();
    removeClass(
      document.querySelectorAll(".pagination-per-page .selected"),
      "selected",
    );
    addClass(this, "selected");
    setCookie("pwg_tags_per_page", perPage);
  },
);

function updateSearchInfo(): void {
  if (val(document.querySelectorAll(".search-input")) !== "") {
    const number = dataTags.filter(isDataSearched).length;
    if (number > 1) {
      html(
        document.querySelectorAll(".search-info"),
        strTagsFound.replace("%d", String(number)),
      );
    } else {
      html(
        document.querySelectorAll(".search-info"),
        strTagFound.replace("%d", String(number)),
      );
    }
  } else {
    html(document.querySelectorAll(".search-info"), "");
  }
}

ready(function () {
  document
    .querySelector("h1")
    ?.insertAdjacentHTML(
      "beforeend",
      '<span class="badge-number">' +
        String(pwg_getPageData<number>("total")) +
        "</span>",
    );
});

if (cookie("pwg_tags_per_page") === undefined) {
  setCookie("pwg_tags_per_page", "100");
}

ready(function () {
  function setPagination(): void {
    const test = cookie("pwg_tags_per_page");
    removeClass(
      document.querySelectorAll(".pagination-per-page .selected"),
      "selected",
    );
    if (test !== undefined) {
      // The per-page links' own ids are bare digits ("100", "200", ...) --
      // querySelectorAll throws on an unescaped leading-digit id.
      document.querySelector<HTMLElement>("#" + escapeId(test))?.click();
    }
  }

  setPagination();
});
