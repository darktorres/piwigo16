import { jConfirm_alert_options, jConfirm_warning_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { alert, confirm } from "../../../default/js/vendor/jconfirm";
import {
  addClass,
  append,
  attr,
  data,
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
} from "../../../default/js/vendor/dom";
import type { operations } from "../../../../openapi/client/schema";
export {};

const str_yes_delete_confirmation = pwg_getPageString("Yes, delete");
const str_no_delete_confirmation = pwg_getPageString(
  "No, I have changed my mind",
);
const str_delete = pwg_getPageString(
  "Are you sure you want to delete comment #%s?",
);
const str_deletes = pwg_getPageString(
  'Are you sure you want to delete "%d" comments?',
);
const pwg_token = pwg_getPageData<string>("csrf_token");
const str_an_error_has = pwg_getPageString("An error has occured");
const str_comment_validated = pwg_getPageString(
  "The comment has been validated.",
);
const str_comments_validated = pwg_getPageString(
  "The comments have been validated.",
);
const str_and_others = pwg_getPageString("and %s others");

const advancedFilters = document.getElementById("advancedFilters");
const switchMode = document.getElementById(
  "toggleSelectionMode",
) as HTMLInputElement | null;
const commentContainer = document.getElementById("commentContainer");
const commentsAll = document.getElementById("commentsAll");
const commentsValidated = document.getElementById("commentsValidated");
const commentsPending = document.getElementById("commentsPending");
const commentsList = document.getElementById("commentsList");
const commentsNb = document.querySelectorAll("#commentsNb a");
const filterAuthor = document.getElementById(
  "filter_author",
) as HTMLSelectElement | null;
const filterDateStart = document.getElementById(
  "filter_date_start",
) as HTMLInputElement | null;
const filterDateEnd = document.getElementById(
  "filter_date_end",
) as HTMLInputElement | null;
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
  author_id?: string | number;
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
let commentsState: CommentListResponse = {} as CommentListResponse;
const commentsParams: CommentsFilterParams = {
  status: "all",
  page: 0,
  per_page: 5,
};

let updateAuthorId = true;
let searchTimeOut: ReturnType<typeof setTimeout> | undefined;
let selectionMode = false;
let commentsSelected: (string | number)[] = [];

ready(function () {
  on(
    document.querySelectorAll("#commentFilters"),
    "click",
    function (event: Event) {
      (event.currentTarget as Element).classList.toggle("advanced-filter-open");
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

      if (
        commentContainer === null ||
        !commentContainer.classList.contains("active")
      ) {
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
    function (event: Event) {
      const target = event.currentTarget as Element;
      commentsParams.status = target.getAttribute("data-status")!;
      commentsParams.page = 0;
      getComments(commentsParams);
    },
  );

  on(commentsNb, "click", function (event: Event) {
    const nb = (event.currentTarget as Element).textContent ?? "";
    updateNbComments(nb);
    commentsParams.page = 0;
    getComments(commentsParams);
  });

  on(document.querySelectorAll("#closeModalViewComment"), "click", function () {
    closeModalViewComment();
  });

  on(
    document.querySelectorAll("#commentSearchInput"),
    "input",
    function (event: Event) {
      clearTimeout(searchTimeOut);
      const target = event.currentTarget as HTMLInputElement;
      searchTimeOut = setTimeout(() => {
        // Real #commentSearchInput's own value: a plain text input, always
        // a string.
        const search = target.value;

        delete commentsParams.author_id;
        delete commentsParams.f_min_date;
        delete commentsParams.f_max_date;

        commentsParams.search = search;
        getComments(commentsParams);
      }, 300);
    },
  );

  on(document.querySelectorAll("#commentsResetFilters"), "click", function () {
    commentsClearFilters();
  });

  on(window, "keydown", function (e: Event) {
    if ((e as KeyboardEvent).key === "Escape") {
      closeModalViewComment();
    }
  });

  // get comments and set display
  commentsParams.per_page = window.localStorage.getItem("adminCommentsNB") ?? 5;
  updateNbComments(commentsParams.per_page);
  getComments(commentsParams);
});

function getComments(params: CommentsFilterParams) {
  void ajax({
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
    success: (data: CommentListResponse) => {
      // for debug
      // console.log(data);
      commentsState = { ...data };
      commentsDisplaySummary(data.summary);
      displayComments(data.comments);
      commentsDiplayPagination(data.paging);
      commentsDisplayFilters(data.filters);

      delete commentsParams.search;
    },
    error: (e) => {
      console.error(e);
      alert({
        title: str_an_error_has,
        content: "",
        ...jConfirm_warning_options,
      });
    },
  });
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
    const raw_lenght = rawContent.length;
    const preview =
      raw_lenght > 50 ? rawContent.substring(0, 50) + "..." : rawContent;
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
    function (e: Event) {
      e.stopPropagation();
      const id = data(e.currentTarget as Element, "idx") as string | number;
      deleteComment([id]);
    },
  );

  off(document.querySelectorAll(".comment-validate"), "click");
  on(
    document.querySelectorAll(".comment-validate"),
    "click",
    function (e: Event) {
      e.stopPropagation();
      const id = data(e.currentTarget as Element, "idx") as string | number;
      validateComment([id]);
    },
  );

  off(document.querySelectorAll(".comment-content"), "click");
  on(
    document.querySelectorAll(".comment-content"),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const id = data(el, "idx") as string | number;
      if (selectionMode) {
        const checkbox = find(el, ".comment-select-checkbox")[0];
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

          commentsSelected = commentsSelected.filter((idx) => idx != id);
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

  if (paging.totalPages == 0) {
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
        "#" + escapeId("comments_page_" + (paging.page + 1)),
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
    on(arrows, "click", function (event: Event) {
      const el = event.currentTarget as Element;
      let newPage = commentsParams.page;
      if (el.classList.contains("left")) {
        newPage = newPage - 1;
      } else {
        newPage = newPage + 1;
      }

      if (newPage == -1 || newPage > commentsState.paging.totalPages) {
        return;
      }
      commentsParams.page = newPage;
      getComments(commentsParams);
    });
  }

  off(document.querySelectorAll(".comments-paging"), "click");
  on(
    document.querySelectorAll(".comments-paging"),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const newPage = Number(el.getAttribute("data-page")) - 1;
      commentsParams.page = newPage;
      getComments(commentsParams);
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

      if (!min) {
        delete commentsParams.f_min_date;
      } else {
        // Real filter_date_start's own value: a plain date input, always
        // a string.
        commentsParams.f_min_date = min;
      }

      if (filterDateEnd !== null) filterDateEnd.min = min ?? "";
      commentsParams.page = 0;
      getComments(commentsParams);
    });
  }

  if (filterDateEnd !== null) {
    off(filterDateEnd, "change");
    on(filterDateEnd, "change", function () {
      const max = val([filterDateEnd]);

      if (!max) {
        delete commentsParams.f_max_date;
      } else {
        // Real filter_date_end's own value: a plain date input, always a
        // string.
        commentsParams.f_max_date = max;
      }

      if (filterDateStart !== null) filterDateStart.max = max ?? "";
      commentsParams.page = 0;
      getComments(commentsParams);
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
      <option value="${a.author_id}">${a.author} (${a.nb_authors})</option>
      `,
    );
  });

  off(filterAuthor, "change");
  on(filterAuthor, "change", function () {
    const authorId = filterAuthor.value;

    if (!authorId) {
      delete commentsParams.author_id;
    } else {
      // Real filter_author's own value: a plain <select>, always a string.
      commentsParams.author_id = authorId;
    }

    commentsParams.page = 0;
    updateAuthorId = false;
    getComments(commentsParams);
  });
}

function updateNbComments(nb: string | number) {
  removeClass(commentsNb, "selected-pagination");
  addClass(
    document.querySelectorAll("#" + escapeId("pagination-per-page-" + nb)),
    "selected-pagination",
  );

  commentsParams.per_page = nb;
  window.localStorage.setItem("adminCommentsNB", String(nb));
}

function showModalViewComment(id: string | number) {
  const comment = commentsState.comments.filter((c) => c.id == id)[0] ?? null;
  if (!comment || modalViewComment === null) return;

  const item = document.querySelector("#" + escapeId(id));
  text(find(modalViewComment, ".comment-datetime"), comment.date);
  remove(find(modalViewComment, ".comment-author"));
  const infos = find(modalViewComment, ".comments-modal-infos")[0];
  const authorClone =
    item !== null ? find(item, ".comment-author")[0] : undefined;
  if (infos !== undefined && authorClone !== undefined) {
    infos.insertBefore(authorClone.cloneNode(true), infos.firstChild);
  }
  attr(find(modalViewComment, ".comments-modal-img"), "src", comment.mediumUrl);
  const imgI = find(modalViewComment, ".comments-modal-img-i")[0];
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
        validateComment([id]);
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

function validateComment(id: (string | number)[]) {
  const idLenght = id.length ?? 1;

  void ajax({
    url: "api/v1/comments/actions/validate",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": pwg_token,
    },
    data: JSON.stringify({
      commentIds: id,
    }),
    dataType: "json",
    success: function (
      _data: operations["commentValidate"]["responses"][200]["content"]["application/json"],
    ) {
      alert({
        title: idLenght > 1 ? str_comments_validated : str_comment_validated,
        content: "",
        ...jConfirm_alert_options,
      });
      getComments(commentsParams);
    },
    error: function (e) {
      console.error(e);
      alert({
        title: str_an_error_has,
        content: "",
        ...jConfirm_warning_options,
      });
    },
  });
}

function deleteComment(id: (string | number)[]) {
  const idLenght = id.length ?? 1;

  confirm({
    title:
      idLenght > 1
        ? str_deletes.replace("%d", String(idLenght))
        : str_delete.replace("%s", String(id)),
    titleClass: "jconfirmDeleteConfirm",
    content: "",
    boxWidth: "30%",
    type: "red",
    buttons: {
      confirm: {
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          void ajax({
            url: "api/v1/comments/actions/delete",
            type: "POST",
            contentType: "application/json",
            headers: {
              "X-CSRF-Token": pwg_token,
            },
            data: JSON.stringify({
              commentIds: id,
            }),
            dataType: "json",
            success: function (
              _data: operations["commentDelete"]["responses"][200]["content"]["application/json"],
            ) {
              getComments(commentsParams);
            },
            error: function (e) {
              console.error(e);
            },
          });
        },
      },
      cancel: {
        text: str_no_delete_confirmation,
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
    const id = el.id;
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
    const id = el.id;
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
          str_and_others.replace(/%s/g, String(commentsSelected.length - 5)),
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
    function (event: Event) {
      const id = (event.currentTarget as Element).id.split("_")[1];
      if (!id) return;
      const target = document.querySelector(
        "#" + escapeId(id) + " .comment-content",
      );
      if (target !== null) trigger([target], "click");
    },
  );

  off(document.querySelectorAll("#ValisateSelectionMode"), "click");
  on(document.querySelectorAll("#ValisateSelectionMode"), "click", function () {
    validateComment(commentsSelected);
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
  getComments(commentsParams);
}
