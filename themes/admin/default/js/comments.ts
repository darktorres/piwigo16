import {
  jConfirmAlertOptions,
  jConfirmWarningOptions,
} from "./jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { alert, confirm } from "../../../default/js/vendor/widgets/jconfirm";
import {
  addClass,
  append,
  attr,
  dataId,
  empty,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hide,
  html,
  off,
  on,
  parseHtml,
  ready,
  remove,
  removeClass,
  setData,
  setVal,
  show,
  text,
  toggle,
  trigger,
  val,
  valId,
} from "../../../default/js/vendor/utils/dom";
import type { operations } from "../../../../openapi/client/schema";

const strYesDeleteConfirmation = pwg_getPageString("Yes, delete");
const strNoDeleteConfirmation = pwg_getPageString("No, I have changed my mind");
const strDelete = pwg_getPageString(
  "Are you sure you want to delete comment #%s?",
);
const strDeletes = pwg_getPageString(
  'Are you sure you want to delete "%d" comments?',
);
const pwgToken = pwg_getPageData<string>("csrf_token");
const strAnErrorHas = pwg_getPageString("An error has occured");
const strCommentValidated = pwg_getPageString(
  "The comment has been validated.",
);
const strCommentsValidated = pwg_getPageString(
  "The comments have been validated.",
);
const strAndOthers = pwg_getPageString("and %s others");

const advancedFilters = document.getElementById("advancedFilters");
const switchMode = document.querySelector<HTMLInputElement>(
  "#toggleSelectionMode",
);
const commentContainer = document.getElementById("commentContainer");
const commentsAll = document.getElementById("commentsAll");
const commentsValidated = document.getElementById("commentsValidated");
const commentsPending = document.getElementById("commentsPending");
const commentsList = document.getElementById("commentsList");
const commentsNb = document.querySelectorAll("#commentsNb a");
const filterAuthor =
  document.querySelector<HTMLSelectElement>("#filter_author");
const filterDateStart =
  document.querySelector<HTMLInputElement>("#filter_date_start");
const filterDateEnd =
  document.querySelector<HTMLInputElement>("#filter_date_end");
const commentsSelectController = document.getElementById(
  "commentsSelectController",
);
const tabFilters = document.getElementById("tabFilters");
const commentsSelectedArea = document.getElementById("commentsSelected");
const commentsSelectedOthers = document.getElementById(
  "commentsSelectedOthers",
);
const modalViewComment = document.getElementById("modalViewComment");

const commentsPaginElipsis = "<span>...</span>";
const commentsPaginItems =
  '<a id="comments_page_%d" class="comments-paging" data-page="%d">%d</a>';
const commentsPaginItemsCurrent =
  '<a id="comments_page_%d" class="comments-paging comment-paging-current" data-page="%d">%d</a>';
const commentsOptionsFiltersAuthor = '<option value="" selected="">--</option>';
const commentsSelectedList =
  '<div class="comments-selected-item"><a class="icon-cancel comments-selected-remove" id="deletecomment_%d"></a> <p>#%d</p></div>';

// The real shape of the GET api/v1/comments response (CommentListController.php),
// via the existing OpenAPI schema.
type CommentListResponse =
  operations["commentList"]["responses"][200]["content"]["application/json"];
type CommentEntry = CommentListResponse["comments"][number];

interface CommentsFilterParams {
  status: string;
  page: number;
  per_page: number | string;
  search?: string;
  author_id?: number;
  f_min_date?: string;
  f_max_date?: string;
  // Never actually set anywhere in this file (confirmed via grep) --
  // only read/deleted. Kept, not pruned: harmless, and out of this
  // phase's own scope (typing existing behavior, not trimming it).
  image_id?: string | number;
}

// Placeholder until the first `getComments()` call (fired unconditionally
// on document ready, below) populates it for real -- every real read of
// `commentsState.comments`/`.paging` only happens from handlers wired up
// after that first call succeeds.
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- deliberate placeholder, never read before the unconditional getComments() call below replaces it with a real response.
let commentsState: CommentListResponse = {} as CommentListResponse;
const commentsParams: CommentsFilterParams = {
  status: "all",
  page: 0,
  per_page: 5,
};

let updateAuthorId = true;
let searchTimeOut: ReturnType<typeof setTimeout> | undefined;
let selectionMode = false;
let commentsSelected: number[] = [];

ready(function () {
  on(
    document.querySelectorAll("#commentFilters"),
    "click",
    function (this: Element) {
      this.classList.toggle("advanced-filter-open");
      if (advancedFilters !== null) {
        toggle(advancedFilters);
      }
    },
  );

  if (switchMode !== null) {
    on(switchMode, "change", function () {
      const contentSelectMode = document.getElementById("contentSelectMode");
      if (contentSelectMode !== null) {
        toggle(contentSelectMode);
      }
      document
        .querySelectorAll("#headerSelectMode, #contentSelectMode")
        .forEach((el) => {
          el.classList.toggle("selection-mode");
        });
      if (commentContainer !== null) {
        commentContainer.classList.toggle("active");
      }

      if (commentContainer?.classList.contains("active") !== true) {
        selectionMode = false;
        hide(document.querySelectorAll(".comment-select-checkbox"));

        show(document.querySelectorAll(".comment-buttons"));
        if (commentsSelectController !== null) {
          removeClass(commentsSelectController, "show");
        }
        if (tabFilters !== null) show(tabFilters);
        commentsUnselectAll();
      } else {
        selectionMode = true;
        show(document.querySelectorAll(".comment-select-checkbox"));

        hide(document.querySelectorAll(".comment-buttons"));
        if (tabFilters !== null) hide(tabFilters);
        if (commentsSelectController !== null) {
          addClass(commentsSelectController, "show");
        }
      }
    });
  }

  on(document.querySelectorAll("#selectAll"), "click", function () {
    commentsSelectAll();
  });

  on(document.querySelectorAll("#selectNone"), "click", function () {
    commentsUnselectAll();
  });

  on(document.querySelectorAll("#selectInvert"), "click", function () {
    commentsInvertSelect();
  });

  on(
    document.querySelectorAll(".tab-filters input"),
    "change",
    function (this: Element) {
      commentsParams.status = this.getAttribute("data-status")!;
      commentsParams.page = 0;
      void getComments(commentsParams);
    },
  );

  on(commentsNb, "click", function (this: Element) {
    const nb = this.textContent;
    updateNbComments(nb);
    commentsParams.page = 0;
    void getComments(commentsParams);
  });

  on(document.querySelectorAll("#closeModalViewComment"), "click", function () {
    closeModalViewComment();
  });

  on(
    document.querySelectorAll("#commentSearchInput"),
    "input",
    function (this: HTMLInputElement) {
      clearTimeout(searchTimeOut);
      searchTimeOut = setTimeout(() => {
        const search = this.value;

        delete commentsParams.author_id;
        delete commentsParams.f_min_date;
        delete commentsParams.f_max_date;

        commentsParams.search = search;
        void getComments(commentsParams);
      }, 300);
    },
  );

  on(document.querySelectorAll("#commentsResetFilters"), "click", function () {
    commentsClearFilters();
  });

  on(window, "keydown", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    if ((e as KeyboardEvent).key === "Escape") {
      closeModalViewComment();
    }
  });

  // get comments and set display
  commentsParams.per_page = window.localStorage.getItem("adminCommentsNB") ?? 5;
  updateNbComments(commentsParams.per_page);
  void getComments(commentsParams);
});

async function getComments(params: CommentsFilterParams): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/comments",
      type: "GET",
      dataType: "json",
      data: {
        status: params.status,
        page: params.page,
        perPage: params.per_page,
        search: params.search,
        authorId: params.author_id,
        minDate: params.f_min_date,
        maxDate: params.f_max_date,
        imageId: params.image_id,
      },
    })) as CommentListResponse;

    commentsState = { ...response };
    commentsDisplaySummary(response.summary);
    displayComments(response.comments);
    commentsDiplayPagination(response.paging);
    commentsDisplayFilters(response.filters);

    delete commentsParams.search;
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    alert({
      title: strAnErrorHas,
      content: "",
      ...jConfirmWarningOptions,
    });
  }
}

function commentsDisplaySummary(summary: CommentListResponse["summary"]) {
  if (commentsAll !== null) text([commentsAll], String(summary.allComments));
  if (commentsValidated !== null)
    text([commentsValidated], String(summary.validated));
  if (commentsPending !== null)
    text([commentsPending], String(summary.pending));
}

function displayComments(comments: CommentListResponse["comments"]) {
  const template = document.querySelector(".comment-template");
  if (commentsList !== null) empty(commentsList);
  comments.forEach((comment: CommentEntry) => {
    if (template === null || commentsList === null) return;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const clone = template.cloneNode(true) as Element;
    removeClass(clone, "comment-template");
    addClass(clone, "comment");

    attr(clone, "id", String(comment.id));
    attr(find(clone, ".comment-img"), "src", comment.mediumUrl);
    // contentRaw is genuinely nullable (CommentEntity::$content is
    // `?string`) -- a null raw comment previously threw here
    // (`.length` on null) under the old `any` typing. Treated as empty,
    // matching every other nullable-string display field in this file.
    const rawContent = comment.contentRaw ?? "";
    const rawLenght = rawContent.length;
    const preview =
      rawLenght > 50 ? rawContent.substring(0, 50) + "..." : rawContent;
    text(find(clone, ".comment-msg"), '"' + preview + '"');
    text(find(clone, ".comment-author-name"), comment.author);
    text(find(clone, ".comment-datetime"), comment.date);
    find(clone, ".comment-delete").forEach((el) => {
      setData(el, "idx", comment.id);
    });
    find(clone, ".comment-validate").forEach((el) => {
      setData(el, "idx", comment.id);
    });
    find(clone, ".comment-content").forEach((el) => {
      setData(el, "idx", comment.id);
    });
    text(find(clone, ".comment-hash"), `#${comment.id}`);
    setVal(find(clone, ".comment-select-checkbox"), String(comment.id));
    attr(find(clone, ".comment-link"), "href", comment.adminLink);
    const authorIcons = find(clone, ".comment-author-icon");

    switch (comment.authorStatus) {
      case "guest":
        addClass(authorIcons, "icon-user-secret icon-yellow");
        break;

      case "webmaster":
        addClass(authorIcons, "icon-user icon-purple");
        break;

      case "admin":
        addClass(authorIcons, "icon-user icon-green");
        break;

      case "main_user":
        addClass(authorIcons, "icon-king icon-blue");
        break;

      default:
        addClass(authorIcons, "icon-user icon-yellow");
        break;
    }

    if (comment.isPending) {
      show(find(clone, ".comment-validate"));
    } else {
      addClass(find(clone, ".comment-container"), "comment-validated");
    }

    commentsList.appendChild(clone);
  });

  off(document.querySelectorAll(".comment-delete"), "click");
  on(
    document.querySelectorAll(".comment-delete"),
    "click",
    function (this: Element, e: Event) {
      e.stopPropagation();
      const id = dataId(this, "idx");
      deleteComment([id]);
    },
  );

  off(document.querySelectorAll(".comment-validate"), "click");
  on(
    document.querySelectorAll(".comment-validate"),
    "click",
    function (this: Element, e: Event) {
      e.stopPropagation();
      const id = dataId(this, "idx");
      void validateComment([id]);
    },
  );

  off(document.querySelectorAll(".comment-content"), "click");
  on(
    document.querySelectorAll(".comment-content"),
    "click",
    function (this: Element) {
      const id = dataId(this, "idx");
      if (selectionMode) {
        const [checkbox] = find(this, ".comment-select-checkbox");
        if (checkbox === undefined) return;

        if (checkbox.classList.contains("icon-circle-empty")) {
          checkbox.classList.remove("icon-circle-empty");
          checkbox.classList.add("icon-ok-circled");
          addClass(
            document.querySelectorAll("#" + escapeId(id)),
            "comment-selected",
          );
          commentsSelected.push(id);
        } else {
          checkbox.classList.remove("icon-ok-circled");
          checkbox.classList.add("icon-circle-empty");
          removeClass(
            document.querySelectorAll("#" + escapeId(id)),
            "comment-selected",
          );

          commentsSelected = commentsSelected.filter((idx) => idx !== id);
        }

        commentsUpdateSelection();
        return;
      }

      showModalViewComment(id);
    },
  );
}

function commentsDiplayPagination(paging: CommentListResponse["paging"]) {
  const container = document.querySelector(".pagination-item-container");
  if (container === null) return;
  empty(container);

  if (paging.totalPages === 0) {
    const pageNumbers = paging.totalPages + 1;
    const page = commentsPaginItems.replace(/%d/g, String(pageNumbers));
    const pageEl = parseHtml(page)[0]!;
    addClass(pageEl, "actual");
    container.appendChild(pageEl);
  } else if (paging.totalPages <= 2) {
    Array.from(Array(paging.totalPages + 1)).forEach((_, i) => {
      const page = commentsPaginItems.replace(/%d/g, String(i + 1));
      container.appendChild(parseHtml(page)[0]!);
    });
    addClass(
      document.querySelectorAll(
        "#" + escapeId("comments_page_" + String(paging.page + 1)),
      ),
      "actual",
    );
  } else {
    const pageOne = commentsPaginItems.replace(/%d/g, String(1));
    const pageLast = commentsPaginItems.replace(
      /%d/g,
      String(paging.totalPages + 1),
    );
    const pageCurrent = commentsPaginItemsCurrent.replace(
      /%d/g,
      String(paging.page + 1),
    );

    switch (paging.page) {
      case 0:
        append(container, pageCurrent + commentsPaginElipsis + pageLast);
        break;

      case paging.totalPages:
        append(container, pageOne + commentsPaginElipsis + pageCurrent);
        break;

      default:
        append(
          container,
          pageOne +
            commentsPaginElipsis +
            pageCurrent +
            commentsPaginElipsis +
            pageLast,
        );
        break;
    }

    const arrows = document.querySelectorAll(".pagination-arrow");
    removeClass(arrows, "unavailable");
    off(arrows, "click");
    on(arrows, "click", function (this: Element) {
      let newPage = commentsParams.page;
      if (this.classList.contains("left")) {
        newPage = newPage - 1;
      } else {
        newPage = newPage + 1;
      }

      if (newPage === -1 || newPage > commentsState.paging.totalPages) {
        return;
      }
      commentsParams.page = newPage;
      void getComments(commentsParams);
    });
  }

  off(document.querySelectorAll(".comments-paging"), "click");
  on(
    document.querySelectorAll(".comments-paging"),
    "click",
    function (this: Element) {
      const newPage = Number(this.getAttribute("data-page")) - 1;
      commentsParams.page = newPage;
      void getComments(commentsParams);
    },
  );
}

function commentsDisplayFilters(filters: CommentListResponse["filters"]) {
  if (updateAuthorId) {
    commentsDisplayAuthors(filters.nbAuthors);
  }
  // reset here to let decide filterAuthor onChange
  updateAuthorId = true;

  const minDate = filters.startedAt?.split(" ")[0] ?? "";
  const maxDate = filters.endedAt?.split(" ")[0] ?? "";
  if (filterDateStart !== null) {
    filterDateStart.value = minDate;
    filterDateStart.min = minDate;
    filterDateStart.max = maxDate;
  }
  if (filterDateEnd !== null) {
    filterDateEnd.value = maxDate;
    filterDateEnd.max = maxDate;
    filterDateEnd.min = minDate;
  }

  if (filterDateStart !== null) {
    off(filterDateStart, "change");
    on(filterDateStart, "change", function () {
      const min = val([filterDateStart]);

      if (min === undefined || min === "") {
        delete commentsParams.f_min_date;
      } else {
        // Real filter_date_start's own value: a plain date input, always
        // a string.
        commentsParams.f_min_date = min;
      }

      if (filterDateEnd !== null) filterDateEnd.min = min ?? "";
      commentsParams.page = 0;
      void getComments(commentsParams);
    });
  }

  if (filterDateEnd !== null) {
    off(filterDateEnd, "change");
    on(filterDateEnd, "change", function () {
      const max = val([filterDateEnd]);

      if (max === undefined || max === "") {
        delete commentsParams.f_max_date;
      } else {
        // Real filter_date_end's own value: a plain date input, always a
        // string.
        commentsParams.f_max_date = max;
      }

      if (filterDateStart !== null) filterDateStart.max = max ?? "";
      commentsParams.page = 0;
      void getComments(commentsParams);
    });
  }
}

function commentsDisplayAuthors(
  nb_authors: CommentListResponse["filters"]["nbAuthors"],
) {
  if (filterAuthor === null) return;
  empty(filterAuthor);
  append([filterAuthor], commentsOptionsFiltersAuthor);

  nb_authors.forEach((a) => {
    append(
      [filterAuthor],
      `
      <option value="${a.author_id ?? ""}">${a.author ?? ""} (${a.nb_authors})</option>
      `,
    );
  });

  off(filterAuthor, "change");
  on(filterAuthor, "change", function () {
    const authorId = valId(filterAuthor);

    if (authorId === null) {
      delete commentsParams.author_id;
    } else {
      commentsParams.author_id = authorId;
    }

    commentsParams.page = 0;
    updateAuthorId = false;
    void getComments(commentsParams);
  });
}

function updateNbComments(nb: string | number) {
  removeClass(commentsNb, "selected-pagination");
  addClass(
    document.querySelectorAll(
      "#" + escapeId("pagination-per-page-" + String(nb)),
    ),
    "selected-pagination",
  );

  commentsParams.per_page = nb;
  window.localStorage.setItem("adminCommentsNB", String(nb));
}

function showModalViewComment(id: number) {
  const comment = commentsState.comments.find((c) => c.id === id) ?? null;
  if (!comment || modalViewComment === null) return;

  const item = document.querySelector("#" + escapeId(id));
  text(find(modalViewComment, ".comment-datetime"), comment.date);
  remove(find(modalViewComment, ".comment-author"));
  const [infos] = find(modalViewComment, ".comments-modal-infos");
  const authorClone =
    item !== null ? find(item, ".comment-author")[0] : undefined;
  if (infos !== undefined && authorClone !== undefined) {
    infos.insertBefore(authorClone.cloneNode(true), infos.firstChild);
  }
  attr(find(modalViewComment, ".comments-modal-img"), "src", comment.mediumUrl);
  const [imgI] = find(modalViewComment, ".comments-modal-img-i");
  if (imgI !== undefined) {
    empty(imgI);
    append(
      imgI,
      `
    <p class="comments-modal-filename">${comment.file}</p>
    <p class="icon-calendar">${comment.imageDateAvailable}</p>
  `,
    );
  }
  html(find(modalViewComment, ".comments-modal-body"), comment.content);

  const validBtn = find(modalViewComment, ".comments-modal-validate");
  if (comment.isPending) {
    show(validBtn);
    off(document.querySelectorAll("#commentsModalValidate"), "click");
    on(
      document.querySelectorAll("#commentsModalValidate"),
      "click",
      function () {
        void validateComment([id]);
        closeModalViewComment();
      },
    );
  } else {
    hide(validBtn);
  }

  off(document.querySelectorAll("#commentsModalDelete"), "click");
  on(document.querySelectorAll("#commentsModalDelete"), "click", function () {
    deleteComment([id]);
    closeModalViewComment();
  });

  fadeIn([modalViewComment]);
}

function closeModalViewComment() {
  if (modalViewComment !== null) fadeOut([modalViewComment]);
  off(document.querySelectorAll("#commentsModalValidate"), "click");
  off(document.querySelectorAll("#commentsModalDelete"), "click");
}

async function validateComment(id: number[]): Promise<void> {
  const idLenght = id.length;

  try {
    await ajax({
      url: "api/v1/comments/actions/validate",
      type: "POST",
      json: {
        commentIds: id,
      },
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      dataType: "json",
    });

    alert({
      title: idLenght > 1 ? strCommentsValidated : strCommentValidated,
      content: "",
      ...jConfirmAlertOptions,
    });
    void getComments(commentsParams);
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    alert({
      title: strAnErrorHas,
      content: "",
      ...jConfirmWarningOptions,
    });
  }
}

function deleteComment(id: number[]) {
  const idLenght = id.length;

  confirm({
    title:
      idLenght > 1
        ? strDeletes.replace("%d", String(idLenght))
        : strDelete.replace("%s", String(id)),
    titleClass: "jconfirmDeleteConfirm",
    content: "",
    boxWidth: "30%",
    type: "red",
    buttons: {
      confirm: {
        text: strYesDeleteConfirmation,
        btnClass: "btn-red",
        action: async function () {
          try {
            await ajax({
              url: "api/v1/comments/actions/delete",
              type: "POST",
              json: {
                commentIds: id,
              },
              headers: {
                "X-CSRF-Token": pwgToken,
              },
              dataType: "json",
            });

            void getComments(commentsParams);
          } catch (e) {
            console.error(e instanceof AjaxError ? e.responseText : e);
          }
        },
      },
      cancel: {
        text: strNoDeleteConfirmation,
      },
    },
  });
}

function commentsUnselectAll() {
  removeClass(document.querySelectorAll(".comment"), "comment-selected");
  const checkboxes = document.querySelectorAll(".comment-select-checkbox");
  removeClass(checkboxes, "icon-ok-circled");
  addClass(checkboxes, "icon-circle-empty");

  commentsSelected = [];
  commentsUpdateSelection();
}

function commentsSelectAll() {
  addClass(document.querySelectorAll(".comment"), "comment-selected");
  const checkboxes = document.querySelectorAll(".comment-select-checkbox");
  removeClass(checkboxes, "icon-circle-empty");
  addClass(checkboxes, "icon-ok-circled");

  commentsSelected = [];
  document.querySelectorAll(".comment-selected").forEach((el) => {
    const id = Number(el.id);
    commentsSelected.push(id);
  });
  commentsUpdateSelection();
}

function commentsInvertSelect() {
  document.querySelectorAll(".comment").forEach((el) => {
    el.classList.toggle("comment-selected");
  });
  document.querySelectorAll(".comment-select-checkbox").forEach((el) => {
    el.classList.toggle("icon-ok-circled");
    el.classList.toggle("icon-circle-empty");
  });

  commentsSelected = [];
  document.querySelectorAll(".comment-selected").forEach((el) => {
    const id = Number(el.id);
    commentsSelected.push(id);
  });
  commentsUpdateSelection();
}

function commentsUpdateSelection() {
  if (commentsSelected.length === 0) {
    hide(document.querySelectorAll("#commentsSelection"));
    show(document.querySelectorAll("#commentsNoSelection"));
    off(document.querySelectorAll(".comments-selected-remove"), "click");
    off(document.querySelectorAll("#ValisateSelectionMode"), "click");
    off(document.querySelectorAll("#DeleteSelectionMode"), "click");

    return;
  }

  if (commentsSelectedArea !== null) empty(commentsSelectedArea);
  let count = 0;
  commentsSelected.forEach((id) => {
    if (count === 5) {
      if (commentsSelectedOthers !== null) {
        text(
          [commentsSelectedOthers],
          strAndOthers.replace(/%s/g, String(commentsSelected.length - 5)),
        );
      }
      return;
    }
    if (commentsSelectedOthers !== null) text([commentsSelectedOthers], "");
    const item = commentsSelectedList.replace(/%d/g, String(id));
    if (commentsSelectedArea !== null) append([commentsSelectedArea], item);
    count++;
  });

  off(document.querySelectorAll(".comments-selected-remove"), "click");
  on(
    document.querySelectorAll(".comments-selected-remove"),
    "click",
    function (this: Element) {
      const [, id] = this.id.split("_");
      if (id === undefined || id === "") return;
      const target = document.querySelector(
        "#" + escapeId(id) + " .comment-content",
      );
      if (target !== null) trigger([target], "click");
    },
  );

  off(document.querySelectorAll("#ValisateSelectionMode"), "click");
  on(document.querySelectorAll("#ValisateSelectionMode"), "click", function () {
    void validateComment(commentsSelected);
    commentsUnselectAll();
  });

  off(document.querySelectorAll("#DeleteSelectionMode"), "click");
  on(document.querySelectorAll("#DeleteSelectionMode"), "click", function () {
    deleteComment(commentsSelected);
    commentsUnselectAll();
  });

  hide(document.querySelectorAll("#commentsNoSelection"));
  show(document.querySelectorAll("#commentsSelection"));
}

function commentsClearFilters() {
  delete commentsParams.author_id;
  delete commentsParams.image_id;
  delete commentsParams.search;
  delete commentsParams.f_min_date;
  delete commentsParams.f_max_date;
  void getComments(commentsParams);
}
