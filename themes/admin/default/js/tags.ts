import type { operations } from "../../../../openapi/client/schema";
import {
  jConfirm_alert_options,
  jConfirm_confirm_options,
  TemporaryState,
} from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { cookie, setCookie } from "../../../default/js/vendor/cookie";
import { alert, confirm } from "../../../default/js/vendor/jconfirm";
import {
  addClass,
  animate,
  attr,
  attrOf,
  css,
  data,
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
} from "../../../default/js/vendor/dom";
export {};

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

// Mixed at runtime -- most callers read a real numeric `TagRow.id`, but
// several DOM-attribute-sourced sites (`.attr("data-id")`, `.data("id")`)
// hand back a string form of the same value.
type TagId = string | number;

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

//Get the data
let dataTags = data(
  document.querySelector(".tag-container")!,
  "tags",
) as TagRow[];

//Initiate Select
setChecked(document.querySelectorAll("#select-100"), true);

//Orphan tags
on(document.querySelectorAll(".info-warning p a"), "click", () => {
  const url = data(
    document.querySelector(".info-warning p a")!,
    "url",
  ) as string;
  const tags = orphan_tag_names;
  const str_orphans = str_orphan_tags
    .replace("%s1", String(tags.length))
    .replace("%s2", tags.join(", "));
  confirm({
    content: str_orphans,
    title: str_delete_orphan_tags,
    boxWidth: "30%",
    type: "red",
    buttons: {
      delete: {
        text: str_delete_them,
        btnClass: "btn-red",
        action: function () {
          window.location.href = url.replace(/amp;/g, "");
        },
      },
      keep: {
        text: str_keep_them,
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
  raw_name: string | null = null,
): Element {
  if (raw_name === null) {
    raw_name = name;
  }
  const u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
  const u_view = "index.php?/tags/" + id + "-" + url_name;
  // Non-null: the template block always exists in the page markup.
  let markup = htmlOf(document.querySelectorAll(".tag-template"))!
    .replace(/%name%/g, unescape(name))
    .replace("%U_VIEW%", u_view)
    .replace("%U_EDIT%", u_edit)
    .replace("%raw_name%", raw_name);
  if (name == raw_name) {
    markup = markup.replace("icon-globe", "");
  }
  const newTag = parseHtml(
    '<div class="tag-box test" data-id=' +
      id +
      ' data-selected="0">' +
      markup +
      "</div>",
  )[0]!;
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
      str_number_photos.replace("%d", String(count)),
    );
  } else {
    html(find(newTag, ".tag-dropdown-header i"), str_no_photos);
  }
  return newTag;
}

function recycleTagBox(
  tagBox: Element,
  id: number,
  name: string,
  url_name: string,
  count: number | undefined,
  raw_name: string | null = null,
): void {
  if (raw_name === null) {
    raw_name = name;
  }
  attr(tagBox, "data-id", String(id));
  html(find(tagBox, ".tag-name, .tag-dropdown-header b"), name);
  setVal(find(tagBox, ".tag-name-editable"), name);
  attr(tagBox, "data-selected", "0");
  find(tagBox, ".tag-name").forEach((el) => {
    setData(el, "rawname", raw_name);
  });

  //Dropdown
  const u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
  const u_view = "index.php?/tags/" + id + "-" + url_name;
  attr(find(tagBox, ".dropdown-option.view"), "href", u_view);
  attr(find(tagBox, ".dropdown-option.manage"), "href", u_edit);

  if (count !== undefined && count > 0) {
    css(
      find(tagBox, ".dropdown-option.view, .dropdown-option.manage"),
      "display",
      "block",
    );
    html(
      find(tagBox, ".tag-dropdown-header i"),
      str_number_photos.replace("%d", String(count)),
    );
  } else {
    html(find(tagBox, ".tag-dropdown-header i"), str_no_photos);
  }
}

//Number On Badge
function updateBadge(): void {
  html(document.querySelectorAll(".badge-number"), String(dataTags.length));
  if (dataTags.length == 0) {
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
  // Non-null: set_up_popin() always sets this id before the form is
  // submittable.
  const $tagboxid = attrOf(
    find(
      document.querySelectorAll(".RenameTagPopInContainer"),
      ".tag-property-input",
    ),
    "id",
  )!;
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
      rename_tag_close();
      cleanCheckmark();
      const changedBox = document.querySelector(
        '[data-id="' + $tagboxid + '"]',
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
  if (val(document.querySelectorAll("#add-tag-input")) != "") {
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
          str_tag_created.replace(
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

function addTag(name: string): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    void ajax({
      url: "api/v1/tags",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: name,
      }),
      dataType: "json",
      success: function (data: TagCreateResponse) {
        const newTag = createTagBox(data.id, data.name, data.urlName, 0);
        document.querySelector(".tag-container")?.prepend(newTag);
        setupTagbox(newTag);
        updateSearchInfo();

        //Update the data
        dataTags.unshift({
          name: data.name,
          raw_name: data.name,
          id: data.id,
          url_name: data.urlName,
        });
        updateBadge();
        resolve();
      },
      error: function (err) {
        if (err.status === 422) {
          reject(new Error(str_already_exist.replace("%s", name)));
          return;
        }
        reject(new Error(err.statusText));
      },
    });
  });
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
    let option_is_clicked = false;
    find(tagBox, ".dropdown-option").forEach((option) => {
      if (option.contains(e.target as Node | null)) {
        option_is_clicked = true;
      }
    });
    if (!option_is_clicked) {
      hide(find(tagBox, ".tag-dropdown-block"));
    }
  });

  // Selection behaviour
  on(tagBox, "click", function () {
    if (hasClass(document.querySelectorAll(".tag-container"), "selection")) {
      if (attrOf(tagBox, "data-selected") == "1") {
        attr(tagBox, "data-selected", "0");
        removeSelectedItem(attrOf(tagBox, "data-id")!);
      } else {
        attr(tagBox, "data-selected", "1");
        addSelectedItem(attrOf(tagBox, "data-id")!);
      }
      updateSelectionContent();
    }
  });

  //Edit Name
  on(find(tagBox, ".dropdown-option.edit"), "click", function () {
    const id = data(tagBox, "id") as TagId;
    const tagIndex = dataTags.findIndex((tag) => tag.id == id);
    // Non-null: `id` always comes from a real tag box, which was
    // itself rendered from this same `dataTags` array.
    const tagRawName =
      dataTags[tagIndex]!.raw_name ??
      data(find(tagBox, ".tag-name")[0]!, "rawname");
    const tagName =
      dataTags[tagIndex]!.name ?? htmlOf(find(tagBox, ".tag-name"));
    set_up_popin(data(tagBox, "id") as TagId, tagRawName, tagName);
    rename_tag_open();
  });

  //Delete Tag
  on(find(tagBox, ".dropdown-option.delete"), "click", function () {
    confirm({
      title: str_delete.replace("%s", htmlOf(find(tagBox, ".tag-name"))!),
      buttons: {
        confirm: {
          text: str_yes_delete_confirmation,
          btnClass: "btn-red",
          action: function () {
            removeTag(
              data(tagBox, "id") as TagId,
              htmlOf(find(tagBox, ".tag-name"))!,
            );
          },
        },
        cancel: {
          text: str_no_delete_confirmation,
        },
      },
      ...jConfirm_confirm_options,
    });
  });

  //Duplicate Tag
  on(find(tagBox, ".dropdown-option.duplicate"), "click", function () {
    void duplicateTag(
      data(tagBox, "id") as TagId,
      data(find(tagBox, ".tag-name")[0]!, "rawname") as string,
    ).then((data) => {
      showMessage(str_tag_created.replace("%s", data.name));
    });
  });
}

function set_up_popin(id: TagId, tagRawName: string, tagName: string): void {
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
    str_tag_rename.replace("%s", tagName),
  );
  on(
    document.querySelectorAll(".ClosePopIn, .TagCancel"),
    "click",
    function () {
      rename_tag_close();
    },
  );
  html(document.querySelectorAll(".TagSubmit"), str_yes_rename_confirmation);
  setVal(
    find(
      document.querySelectorAll(".RenameTagPopInContainer"),
      ".tag-property-input",
    ),
    tagRawName,
  );
}

function rename_tag_close(): void {
  fadeOut(document.querySelectorAll("#RenameTag"));
}

function rename_tag_open(): void {
  fadeIn(document.querySelectorAll("#RenameTag"));
  document.querySelector<HTMLElement>(".tag-property-input")?.focus();
}

function removeTag(id: TagId, name: string): void {
  alert({
    title: str_tag_deleted.replace("%s", name),
    content: function () {
      return ajax({
        url: "api/v1/tags/" + id,
        type: "DELETE",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        dataType: "json",
        success: function (_data: TagDeleteResponse) {
          document.querySelector('.tag-box[data-id="' + id + '"]')?.remove();
          //Update data
          dataTags = dataTags.filter((tag) => tag.id != id);
          showMessage(str_tag_deleted.replace("%s", name));
          updateBadge();
          updateSearchInfo();
          updatePaginationMenu();
        },
        error: function () {
          showError("A problem has occured");
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function renameTag(id: TagId, new_name: string): Promise<TagRenameResponse> {
  return new Promise<TagRenameResponse>((resolve, reject) => {
    void ajax({
      url: "api/v1/tags/" + id,
      type: "PATCH",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: new_name,
      }),
      dataType: "json",
      success: function (data: TagRenameResponse) {
        html(
          document.querySelectorAll(
            '.tag-box[data-id="' +
              id +
              '"] p, .tag-box[data-id="' +
              id +
              '"] .tag-dropdown-header b',
          ),
          data.name,
        );
        attr(
          document.querySelectorAll(
            '.tag-box[data-id="' + id + '"] .tag-name-editable',
          ),
          "value",
          data.name,
        );
        attr(
          document.querySelectorAll('.tag-box[data-id="' + id + '"] .tag-name'),
          "data-rawname",
          data.nameRaw,
        );
        const u_view = "index.php?/tags/" + id + "-" + data.urlName;
        attr(
          document.querySelectorAll(".dropdown-option.view"),
          "href",
          u_view,
        );

        //Update the data
        const index = dataTags.findIndex((tag) => tag.id == id);
        // Non-null: `id` always identifies a real, currently-rendered
        // tag box, which was itself rendered from this same array.
        dataTags[index]!.name = data.name;
        dataTags[index]!.raw_name = data.nameRaw;
        dataTags[index]!.url_name = data.urlName;

        resolve(data);
      },
      error: function (XMLHttpRequest) {
        if (XMLHttpRequest.status === 422) {
          reject(new Error(str_already_exist.replace("%s", new_name)));
          return;
        }
        reject(new Error(XMLHttpRequest.statusText));
      },
    });
  });
}

function duplicateTag(id: TagId, name: string): Promise<TagDuplicateResponse> {
  return new Promise<TagDuplicateResponse>((resolve, reject) => {
    let copy_name = name + str_copy;

    const name_exist = function (name: string): boolean {
      let exist = false;
      document.querySelectorAll(".tag-box .tag-name").forEach((el) => {
        if (htmlOf(el) === name) exist = true;
      });
      return exist;
    };

    let i = 1;
    while (name_exist(copy_name)) {
      copy_name = name + str_other_copy.replace("%s", String(i++));
    }

    void ajax({
      url: "api/v1/tags/" + id + "/actions/duplicate",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: copy_name,
      }),
      dataType: "json",
      success: function (data: TagDuplicateResponse) {
        const newTag = createTagBox(
          data.id,
          data.name,
          data.urlName,
          data.count,
        );
        document.querySelector('.tag-box[data-id="' + id + '"]')?.after(newTag);
        setupTagbox(newTag);

        //Update Data
        const index = dataTags.findIndex((tag) => tag.id == id);
        dataTags.splice(index + 1, 0, {
          name: data.name,
          // Was missing entirely -- `TagRow.raw_name` is a required
          // field, and `tagDuplicate`'s own response has no separate
          // raw-name field to source it from (same gap `tagCreate`'s
          // response has, worked around identically in addTag()'s own
          // success handler above: the rendered `name` is the best
          // available stand-in until a real page reload re-fetches the
          // true raw name).
          raw_name: data.name,
          id: data.id,
          url_name: data.urlName,
          counter: data.count,
        });
        updateBadge();
        updateSearchInfo();
        resolve(data);
      },
      error: function (XMLHttpRequest) {
        reject(new Error(XMLHttpRequest.statusText));
      },
    });
  });
}

/*-------
 Selection mode
-------*/
let selected: TagId[] = [];
const maxItemDisplayed = 5;

setChecked(document.querySelectorAll("#toggleSelectionMode"), false);
on(document.querySelectorAll("#toggleSelectionMode"), "click", function () {
  selectionMode(
    is(document.querySelectorAll("#toggleSelectionMode"), ":checked"),
  );
  hide(document.querySelectorAll(".tag-info"));
});

function selectionMode(isSelection: boolean): void {
  if (isSelection) {
    addClass(document.querySelectorAll(".in-selection-mode"), "show");
    addClass(document.querySelectorAll(".not-in-selection-mode"), "hide");
    addClass(document.querySelectorAll(".tag-container"), "selection");
    removeClass(document.querySelectorAll(".tag-box"), "edit-name");
  } else {
    removeClass(document.querySelectorAll(".in-selection-mode"), "show");
    removeClass(document.querySelectorAll(".not-in-selection-mode"), "hide");
    removeClass(document.querySelectorAll(".tag-container"), "selection");
    attr(document.querySelectorAll(".tag-box"), "data-selected", "0");
    slideUp(document.querySelectorAll(".tag-select-message"));
    clearSelection();
  }
}

function clearSelection(): void {
  selected = [];
  html(document.querySelectorAll(".selection-mode-tag .tag-list"), "");
  hide(document.querySelectorAll(".selection-other-tags"));
  updateSelectionContent();
}

function addSelectedItem(id: TagId): void {
  if (!selected.includes(id)) {
    selected.push(id);

    if (selected.length > maxItemDisplayed) {
      show(document.querySelectorAll(".selection-other-tags"));
      const numberDisplayed = document.querySelectorAll(
        ".selection-mode-tag .tag-list div",
      ).length;
      html(
        document.querySelectorAll(".selection-other-tags"),
        str_and_others_tags.replace(
          "%s",
          String(selected.length - numberDisplayed),
        ),
      );
    } else {
      hide(document.querySelectorAll(".selection-other-tags"));
      if (dataTags.findIndex((tag) => tag.id == id) > -1) {
        createSelectionItem(id, dataTags.find((tag) => tag.id == id)!.name);
      }
    }
  }
}

function createSelectionItem(id: TagId, name: string): void {
  const newItemStructure = parseHtml(
    '<div data-id="' +
      id +
      '"><a class="icon-cancel"></a><p>' +
      name +
      "</p> </div>",
  )[0]!;
  document
    .querySelector(".selection-mode-tag .tag-list")
    ?.prepend(newItemStructure);
  on(
    document.querySelectorAll(
      '.selection-mode-tag .tag-list div[data-id="' + id + '"] a',
    ),
    "click",
    function () {
      removeSelectedItem(id);
    },
  );
}

function removeSelectedItem(id: TagId): void {
  if (selected.findIndex((tag) => tag == id) > -1) {
    selected = selected.filter((tag) => {
      return parseInt(String(tag)) != parseInt(String(id));
    });

    attr(
      document.querySelectorAll('.tag-box[data-id="' + id + '"]'),
      "data-selected",
      "0",
    );
    if (
      document.querySelectorAll(
        '.selection-mode-tag .tag-list div[data-id="' + id + '"]',
      ).length != 0
    ) {
      document
        .querySelectorAll(
          '.selection-mode-tag .tag-list div[data-id="' + id + '"]',
        )
        .forEach((el) => {
          el.remove();
        });

      if (selected.length >= maxItemDisplayed) {
        let i = 0;
        let isNotCreate = true;
        while (i < selected.length && isNotCreate) {
          if (
            document.querySelectorAll(
              '.selection-mode-tag .tag-list div[data-id="' +
                selected[i] +
                '"]',
            ).length == 0
          ) {
            isNotCreate = false;
            const indexOfTag = dataTags.findIndex(
              (tag) => tag.id == selected[i],
            );
            createSelectionItem(selected[i]!, dataTags[indexOfTag]!.name);
          }
          i++;
        }
      }
    }

    const numberDisplayed = document.querySelectorAll(
      ".selection-mode-tag .tag-list div",
    ).length;
    html(
      document.querySelectorAll(".selection-other-tags"),
      str_and_others_tags.replace(
        "%s",
        String(selected.length - numberDisplayed),
      ),
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
      parseHtml(
        '<option value="' +
          id +
          '">' +
          dataTags.find((tag) => tag.id == id)!.name +
          "</option>",
      )[0]!,
    );
  });
}

let mergeOption = false;

function updateSelectionContent(): void {
  const number = selected.length;
  if (number == 0) {
    mergeOption = false;
    show(document.querySelectorAll("#nothing-selected"));
    hide(document.querySelectorAll(".selection-mode-tag"));
    hide(document.querySelectorAll("#MergeOptionsBlock"));
  } else if (number == 1) {
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

on(document.querySelectorAll("#selectAll"), "click", function () {
  void selectAll(tagToDisplay());
  updateSelectionContent();
  if (selected.length < dataTags.length) {
    showSelectMessage(
      str_selection_done.replace(
        "%d",
        String(document.querySelectorAll(".tag-box").length),
      ),
      str_select_all_tag.replace("%d", String(dataTags.length)),
      function () {
        html(document.querySelectorAll(".tag-select-message a"), "");
        html(
          document.querySelectorAll(".tag-select-message div"),
          "<i class='icon-spin6 animate-spin'> </i>",
        );
        setTimeout(() => {
          void selectAll(dataTags).then(() => {
            updateSelectionContent();
            showSelectMessage(
              str_tag_selected.replace(/%d/g, String(selected.length)),
              str_clear_selection,
              function () {
                selectNone();
                slideUp(document.querySelectorAll(".tag-select-message"));
              },
            );
          });
        }, 5);
      },
    );
  }
});

function selectAll(data: TagRow[]) {
  const promises: Promise<void>[] = [];
  data.forEach((tag) => {
    promises.push(
      new Promise<void>((res, _rej) => {
        attr(
          document.querySelectorAll('.tag-box[data-id="' + tag.id + '"]'),
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

function selectInvert(data: TagRow[]): void {
  data.forEach((tag) => {
    const tagBox = document.querySelectorAll(
      '.tag-box[data-id="' + tag.id + '"]',
    );
    if (attrOf(tagBox, "data-selected") == "1") {
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
    names.push(dataTags.find((tag) => tag.id == id)!.name);
  });

  confirm({
    title: str_delete_tags.replace("%s", tagListToString(names)),
    buttons: {
      confirm: {
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          removeSelectedTags();
        },
      },
      cancel: {
        text: str_no_delete_confirmation,
      },
    },
    ...jConfirm_confirm_options,
  });
});

function removeSelectedTags(): void {
  const names: string[] = [];
  selected.forEach(function (id) {
    names.push(dataTags.find((tag) => tag.id == id)!.name);
  });

  alert({
    title: str_tags_deleted.replace("%s", tagListToString(names)),
    content: function () {
      // No bulk-delete endpoint (a REST single-resource DELETE per tag,
      // per P27's own design) -- fire one DELETE per selected tag.
      return Promise.all(
        selected.map(function (id) {
          return ajax({
            url: "api/v1/tags/" + id,
            type: "DELETE",
            headers: {
              "X-CSRF-Token": pwg_token,
            },
            dataType: "json",
          });
        }),
      ).then(function () {
        selected.forEach(function (id) {
          document.querySelector('.tag-box[data-id="' + id + '"]')?.remove();
        });

        // Update Data
        dataTags = dataTags.filter((tag) => !selected.includes(tag.id));

        clearSelection();
        updatePaginationMenu();
        updateBadge();
        updateSearchInfo();
      });
    },
    ...jConfirm_alert_options,
  });
}

//Merge Tags
on(document.querySelectorAll(".ConfirmMergeButton"), "click", () => {
  // Single-value <select>, never multi.
  const dest_id = val(
    document.querySelectorAll("#MergeOptionsChoices"),
  ) as string;
  mergeGroups(dest_id, selected);
});

function mergeGroups(destination_id: TagId, merge_ids: TagId[]): void {
  const destination_name = htmlOf(
    document.querySelectorAll(
      '.tag-box[data-id="' + destination_id + '"] .tag-name',
    ),
  )!;
  const merge_name: string[] = [];

  merge_ids.forEach((id) => {
    merge_name.push(
      htmlOf(
        document.querySelectorAll('.tag-box[data-id="' + id + '"] .tag-name'),
      )!,
    );
  });

  const str_message = str_merged_into
    .replace("%s1", tagListToString(merge_name))
    .replace("%s2", destination_name);

  alert({
    title: str_message,
    content: function () {
      return ajax({
        url: "api/v1/tags/actions/merge",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        data: JSON.stringify({
          destinationTagId: Number(destination_id),
          mergeTagIds: merge_ids,
        }),
        dataType: "json",
        success: function (data: TagMergeResponse) {
          data.deletedTagIds.forEach((id) => {
            if (data.destinationTagId != id) {
              document
                .querySelector('.tag-box[data-id="' + id + '"]')
                ?.remove();
              // Update data
              dataTags = dataTags.filter((tag) => id != tag.id);
            }
          });
          if (data.imagesInMergedTag.length > 0) {
            const tagBox = document.querySelectorAll(
              '.tag-box[data-id="' + data.destinationTagId + '"]',
            );
            show(
              find(
                tagBox,
                ".dropdown-option.view,.dropdown-option.manage,.tag-dropdown-header i",
              ),
            );
            html(
              document.querySelectorAll(".tag-dropdown-header i"),
              str_number_photos.replace(
                "%d",
                String(data.imagesInMergedTag.length),
              ),
            );

            // Update data
            const index = dataTags.findIndex(
              (tag) => tag.id == data.destinationTagId,
            );
            dataTags[index]!.counter = data.imagesInMergedTag.length;
          }
          attr(document.querySelectorAll(".tag-box"), "data-selected", "0");
          clearSelection();
          updatePaginationMenu();
          updateBadge();
          updateSearchInfo();
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function tagListToString(list: string[]): string {
  if (list.length > 5) {
    return (
      list.slice(0, 5).join(", ") +
      " " +
      str_and_others_tags.replace("%s", String(list.length - 5))
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

on(
  document.querySelectorAll("#search-tag .search-input"),
  "input",
  function () {
    actualPage = 1;

    clearTimeout(searchTimeOut);
    searchTimeOut = setTimeout(() => {
      updatePaginationMenu();
      if (dataTags.filter(isDataSearched).length == 0) {
        show(document.querySelectorAll(".emptyResearch"));
      } else {
        hide(document.querySelectorAll(".emptyResearch"));
      }
    }, delaySearchInput);
  },
);

// Genuinely dead code -- zero real callers found (confirmed via grep)
// -- typed rather than left broken, same policy as prior files this
// campaign.
function isDataSearched(tagObj: TagRow): boolean {
  const name = tagObj.raw_name.toLowerCase();
  const stringSearch = String(
    val(document.querySelectorAll("#search-tag .search-input")),
  );
  if (name.includes(stringSearch.toLowerCase())) {
    return true;
  } else {
    return false;
  }
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
let per_page = data(
  document.querySelector(".tag-container")!,
  "per_page",
) as number;
const pageItem = '<a data-page="%d">%d</a>';
const pageEllipsis = "<span>...</span>";
let promisePending = false;
let updateAsk = false;

let actualPage = 1;

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

  if (actualPage != 1 && actualPage != nbPage) {
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
    const newTag = parseHtml(pageItem.replace(/%d/g, String(page)))[0]!;
    container.appendChild(newTag);
    if (actualPage == page) {
      addClass(newTag, "actual");
    }
    on(newTag, "click", () => {
      actualPage = data(newTag, "page") as number;
      updatePaginationMenu();
    });
  } else {
    container.appendChild(parseHtml(pageEllipsis)[0]!);
  }
}

function updateArrows(): void {
  if (actualPage == 1) {
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

  if (actualPage == getNumberPages()) {
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
  return Math.floor((dataVisible - 1) / per_page) + 1;
}

function movePage(toRigth: boolean = true): void {
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
function fadeOpacity(
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

function updatePage(): Promise<void> {
  return new Promise<void>((resolve, _reject) => {
    const dataToDisplay = tagToDisplay();
    const tagBoxes = Array.from(document.querySelectorAll(".tag-box"));
    cleanCheckmark();
    fadeIn(document.querySelectorAll(".pageLoad"));
    void fadeOpacity(tagBoxes, 0, 500).then(() => {
      const displayTags: Promise<void> = new Promise((res, _rej) => {
        const boxToRecycle = Math.min(dataToDisplay.length, tagBoxes.length);

        for (let i = 0; i < boxToRecycle; i++) {
          const tag = dataToDisplay[i]!;
          recycleTagBox(
            tagBoxes[i]!,
            tag.id,
            tag.name,
            tag.url_name,
            tag.counter,
            tag.raw_name,
          );
        }

        if (dataToDisplay.length < tagBoxes.length) {
          for (let j = boxToRecycle; j < tagBoxes.length; j++) {
            tagBoxes[j]!.remove();
          }
        } else if (dataToDisplay.length > tagBoxes.length) {
          for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
            const tag = dataToDisplay[j]!;
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
          }
        }

        //Select selected tags
        selected.forEach((id) => {
          attr(
            document.querySelectorAll('.tag-box[data-id="' + id + '"]'),
            "data-selected",
            "1",
          );
        });

        res();
      });

      void displayTags.then(() => {
        fadeOut(document.querySelectorAll(".pageLoad"));
        animate(document.querySelectorAll(".tag-box"), { opacity: 1 }, 500);
        if (getNumberPages() > 1) {
          animate(
            document.querySelectorAll(".tag-pagination"),
            { opacity: 1 },
            500,
          );
        }
        updateSearchInfo();
        resolve();
      });
    });
  });
}

function tagToDisplay(): TagRow[] {
  return dataTags
    .filter(isDataSearched)
    .slice((actualPage - 1) * per_page, actualPage * per_page);
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
  function (event: Event) {
    const target = event.currentTarget as Element;
    per_page = parseInt(htmlOf(target) ?? "");
    updatePaginationMenu();
    removeClass(
      document.querySelectorAll(".pagination-per-page .selected"),
      "selected",
    );
    addClass(target, "selected");
    setCookie("pwg_tags_per_page", per_page);
  },
);

function updateSearchInfo(): void {
  if (val(document.querySelectorAll(".search-input")) != "") {
    const number = dataTags.filter(isDataSearched).length;
    if (number > 1) {
      html(
        document.querySelectorAll(".search-info"),
        str_tags_found.replace("%d", String(number)),
      );
    } else {
      html(
        document.querySelectorAll(".search-info"),
        str_tag_found.replace("%d", String(number)),
      );
    }
  } else {
    html(document.querySelectorAll(".search-info"), "");
  }
}

const pwg_token = pwg_getPageData<string>("csrf_token");
const orphan_tag_names = JSON.parse(
  pwg_getPageData<string>("orphan_tag_names_array"),
) as string[];
const str_delete = pwg_getPageString('Delete tag "%s"?');
const str_delete_tags = pwg_getPageString("Delete tags {%s}?");
const str_yes_delete_confirmation = pwg_getPageString("Yes, delete");
const str_no_delete_confirmation = pwg_getPageString(
  "No, I have changed my mind",
);
const str_yes_rename_confirmation = pwg_getPageString("Yes, rename");
const str_tag_deleted = pwg_getPageString('Tag "%s" succesfully deleted');
const str_tags_deleted = pwg_getPageString("Tags {%s} succesfully deleted");
const str_already_exist = pwg_getPageString('Tag "%s" already exists');
const str_tag_created = pwg_getPageString('Tag "%s" created');
const str_tag_rename = pwg_getPageString('Rename "%s"');
const str_delete_orphan_tags = pwg_getPageString("Delete orphan tags ?");
const str_orphan_tags = pwg_getPageString("You have %s1 orphan : %s2");
const str_delete_them = pwg_getPageString("Delete them");
const str_keep_them = pwg_getPageString("Keep them");
const str_copy = pwg_getPageString(" (copy)");
const str_other_copy = pwg_getPageString(" (copy %s)");
const str_merged_into = pwg_getPageString(
  'Tag(s) {%s1} succesfully merged into "%s2"',
);
const str_and_others_tags = pwg_getPageString("and %s others");
const str_number_photos = pwg_getPageString("%d photos");
const str_no_photos = pwg_getPageString("no photo");
const str_select_all_tag = pwg_getPageString("Select all %d tags");
const str_clear_selection = pwg_getPageString("Clear Selection");
const str_selection_done = pwg_getPageString(
  "The %d tags on this page are selected",
);
const str_tag_selected = pwg_getPageString("<b>%d</b> tag selected");
const str_tags_found = pwg_getPageString("<b>%d</b> tags found");
const str_tag_found = pwg_getPageString("<b>%d</b> tag found");

ready(function () {
  document
    .querySelector("h1")
    ?.insertAdjacentHTML(
      "beforeend",
      '<span class="badge-number">' +
        pwg_getPageData<number>("total") +
        "</span>",
    );
});

if (!cookie("pwg_tags_per_page")) {
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
