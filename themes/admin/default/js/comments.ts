import { jConfirm_alert_options, jConfirm_warning_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
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

const commentsContainer = $("#comments");
const advancedFilters = $("#advancedFilters");
const switchMode = $("#toggleSelectionMode");
const commentContainer = $("#commentContainer");
const commentsAll = $("#commentsAll");
const commentsValidated = $("#commentsValidated");
const commentsPending = $("#commentsPending");
const commentsList = $("#commentsList");
const commentsNb = $("#commentsNb a");
const filterAuthor = $("#filter_author");
const filterDateStart = $("#filter_date_start");
const filterDateEnd = $("#filter_date_end");
const commentsSelectController = $("#commentsSelectController");
const tabFilters = $("#tabFilters");
const commentsSelectedArea = $("#commentsSelected");
const commentsSelectedOthers = $("#commentsSelectedOthers");
const modalViewComment = $("#modalViewComment");

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
  import("../../../../openapi/client/schema").operations["commentList"]["responses"][200]["content"]["application/json"];
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

$(function () {
  $("#commentFilters").on("click", function () {
    $(this).toggleClass("advanced-filter-open");
    advancedFilters.toggle();
  });

  switchMode.on("change", function () {
    $("#contentSelectMode").toggle();
    $("#headerSelectMode, #contentSelectMode").toggleClass("selection-mode");
    commentContainer.toggleClass("active");

    if (!commentContainer.hasClass("active")) {
      selectionMode = false;
      $(".comment-select-checkbox").hide();

      $(".comment-buttons").show();
      commentsSelectController.removeClass("show");
      tabFilters.show();
      commentsUnselectAll();
    } else {
      selectionMode = true;
      $(".comment-select-checkbox").show();

      $(".comment-buttons").hide();
      tabFilters.hide();
      commentsSelectController.addClass("show");
    }
  });

  $("#selectAll").on("click", function () {
    commentsSelectAll();
  });

  $("#selectNone").on("click", function () {
    commentsUnselectAll();
  });

  $("#selectInvert").on("click", function () {
    commentsInvertSelect();
  });

  $(".tab-filters input").on("change", function () {
    commentsParams.status = $(this).attr("data-status")!;
    commentsParams.page = 0;
    getComments(commentsParams);
  });

  commentsNb.on("click", function () {
    const nb = $(this).text();
    updateNbComments(nb);
    commentsParams.page = 0;
    getComments(commentsParams);
  });

  $("#closeModalViewComment").on("click", function () {
    closeModalViewComment();
  });

  $("#commentSearchInput").on("input", function () {
    clearTimeout(searchTimeOut);
    searchTimeOut = setTimeout(() => {
      // Real #commentSearchInput's own value: a plain text input, always
      // a string.
      const search = $(this).val() as string;

      delete commentsParams.author_id;
      delete commentsParams.f_min_date;
      delete commentsParams.f_max_date;

      commentsParams.search = search;
      getComments(commentsParams);
    }, 300);
  });

  $("#commentsResetFilters").on("click", function () {
    commentsClearFilters();
  });

  $(window).on("keydown", function (e) {
    if (e.key === "Escape") {
      closeModalViewComment();
    }
  });

  // get comments and set display
  commentsParams.per_page = window.localStorage.getItem("adminCommentsNB") ?? 5;
  updateNbComments(commentsParams.per_page);
  getComments(commentsParams);
});

function getComments(params: CommentsFilterParams) {
  $.ajax({
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
    error: (e: JQuery.jqXHR) => {
      console.log(e);
      $.alert({
        title: str_an_error_has,
        content: "",
        ...jConfirm_warning_options,
      });
    },
  });
}

function commentsDisplaySummary(summary: CommentListResponse["summary"]) {
  commentsAll.text(summary.allComments);
  commentsValidated.text(summary.validated);
  commentsPending.text(summary.pending);
}

function displayComments(comments: CommentListResponse["comments"]) {
  commentsList.empty();
  comments.forEach((comment: CommentEntry) => {
    const clone = $(".comment-template").clone();
    clone.removeClass("comment-template").addClass("comment");

    clone.attr("id", comment.id);
    clone.find(".comment-img").attr("src", comment.mediumUrl);
    // contentRaw is genuinely nullable (CommentEntity::$content is
    // `?string`) -- a null raw comment previously threw here
    // (`.length` on null) under the old `any` typing. Treated as empty,
    // matching every other nullable-string display field in this file.
    const rawContent = comment.contentRaw ?? "";
    const raw_lenght = rawContent.length;
    const preview =
      raw_lenght > 50 ? rawContent.substring(0, 50) + "..." : rawContent;
    clone.find(".comment-msg").text('"' + preview + '"');
    clone.find(".comment-author-name").text(comment.author);
    clone.find(".comment-datetime").text(comment.date);
    clone.find(".comment-delete").data("idx", comment.id);
    clone.find(".comment-validate").data("idx", comment.id);
    clone.find(".comment-content").data("idx", comment.id);
    clone.find(".comment-hash").text(`#${comment.id}`);
    clone.find(".comment-select-checkbox").val(comment.id);
    clone.find(".comment-link").attr("href", comment.adminLink);
    const authorIcons = clone.find(".comment-author-icon");

    switch (comment.authorStatus) {
      case "guest":
        authorIcons.addClass("icon-user-secret icon-yellow");
        break;

      case "webmaster":
        authorIcons.addClass("icon-user icon-purple");
        break;

      case "admin":
        authorIcons.addClass("icon-user icon-green");
        break;

      case "main_user":
        authorIcons.addClass("icon-king icon-blue");
        break;

      default:
        authorIcons.addClass("icon-user icon-yellow");
        break;
    }

    if (comment.isPending) {
      clone.find(".comment-validate").show();
    } else {
      clone.find(".comment-container").addClass("comment-validated");
    }

    commentsList.append(clone);
  });

  $(".comment-delete")
    .off("click")
    .on("click", function (e) {
      e.stopPropagation();
      const id = $(this).data("idx") as string | number;
      deleteComment([id]);
    });

  $(".comment-validate")
    .off("click")
    .on("click", function (e) {
      e.stopPropagation();
      const id = $(this).data("idx") as string | number;
      validateComment([id]);
    });

  $(".comment-content")
    .off("click")
    .on("click", function () {
      const id = $(this).data("idx") as string | number;
      if (selectionMode) {
        const checkbox = $(this).find(".comment-select-checkbox");

        if (checkbox.hasClass("icon-circle-empty")) {
          checkbox.removeClass("icon-circle-empty").addClass("icon-ok-circled");
          $(`#${id}`).addClass("comment-selected");
          commentsSelected.push(id);
        } else {
          checkbox.removeClass("icon-ok-circled").addClass("icon-circle-empty");
          $(`#${id}`).removeClass("comment-selected");

          commentsSelected = commentsSelected.filter((idx) => idx != id);
        }

        commentsUpdateSelection();
        return;
      }

      showModalViewComment(id);
    });
}

function commentsDiplayPagination(paging: CommentListResponse["paging"]) {
  const container = $(".pagination-item-container");
  container.empty();

  if (paging.totalPages == 0) {
    const pageNumbers = paging.totalPages + 1;
    const page = commentsPaginItems.replace(/%d/g, String(pageNumbers));
    $(page).addClass("actual").appendTo(container);
  } else if (paging.totalPages <= 2) {
    Array.from(Array(paging.totalPages + 1)).forEach((_, i) => {
      const page = commentsPaginItems.replace(/%d/g, String(i + 1));
      $(page).appendTo(container);
    });
    $(`#comments_page_${paging.page + 1}`).addClass("actual");
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
        container.append(pageCurrent, commentsPaginElipsis, pageLast);
        break;

      case paging.totalPages:
        container.append(pageOne, commentsPaginElipsis, pageCurrent);
        break;

      default:
        container.append(
          pageOne,
          commentsPaginElipsis,
          pageCurrent,
          commentsPaginElipsis,
          pageLast,
        );
        break;
    }

    $(".pagination-arrow")
      .removeClass("unavailable")
      .off("click")
      .on("click", function () {
        let newPage = commentsParams.page;
        if ($(this).hasClass("left")) {
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

  $(".comments-paging")
    .off("click")
    .on("click", function () {
      const newPage = Number($(this).attr("data-page")) - 1;
      commentsParams.page = newPage;
      getComments(commentsParams);
    });
}

function commentsDisplayFilters(filters: CommentListResponse["filters"]) {
  if (updateAuthorId) {
    commentsDisplayAuthors(filters.nbAuthors);
  }
  // reset here to let decide filterAuthor onChange
  updateAuthorId = true;

  const minDate = filters.startedAt?.split(" ")[0] ?? "";
  const maxDate = filters.endedAt?.split(" ")[0] ?? "";
  filterDateStart.val(minDate).attr({ min: minDate, max: maxDate });
  filterDateEnd.val(maxDate).attr({ max: maxDate, min: minDate });

  filterDateStart.off("change").on("change", function () {
    const min = $(this).val();

    if (!min) {
      delete commentsParams.f_min_date;
    } else {
      // Real filter_date_start's own value: a plain date input, always
      // a string.
      commentsParams.f_min_date = min as string;
    }

    filterDateEnd.attr({ min: min });
    commentsParams.page = 0;
    getComments(commentsParams);
  });

  filterDateEnd.off("change").on("change", function () {
    const max = $(this).val();

    if (!max) {
      delete commentsParams.f_max_date;
    } else {
      // Real filter_date_end's own value: a plain date input, always a
      // string.
      commentsParams.f_max_date = max as string;
    }

    filterDateStart.attr({ max: max });
    commentsParams.page = 0;
    getComments(commentsParams);
  });
}

function commentsDisplayAuthors(
  nb_authors: CommentListResponse["filters"]["nbAuthors"],
) {
  filterAuthor.empty();
  filterAuthor.append(commentsOptionsFiltersAuthor);

  nb_authors.forEach((a) => {
    filterAuthor.append(`
      <option value="${a.author_id}">${a.author} (${a.nb_authors})</option>
      `);
  });

  filterAuthor.off("change").on("change", function () {
    const authorId = $(this).val();

    if (!authorId) {
      delete commentsParams.author_id;
    } else {
      // Real filter_author's own value: a plain <select>, always a string.
      commentsParams.author_id = authorId as string;
    }

    commentsParams.page = 0;
    updateAuthorId = false;
    getComments(commentsParams);
  });
}

function updateNbComments(nb: string | number) {
  commentsNb.removeClass("selected-pagination");
  $(`#pagination-per-page-${nb}`).addClass("selected-pagination");

  commentsParams.per_page = nb;
  window.localStorage.setItem("adminCommentsNB", String(nb));
}

function showModalViewComment(id: string | number) {
  const comment = commentsState.comments.filter((c) => c.id == id)[0] ?? null;
  if (!comment) return;

  const item = $(`#${id}`);
  modalViewComment.find(".comment-datetime").text(comment.date);
  modalViewComment.find(".comment-author").remove();
  modalViewComment
    .find(".comments-modal-infos")
    .prepend(item.find(".comment-author").clone());
  modalViewComment.find(".comments-modal-img").attr("src", comment.mediumUrl);
  modalViewComment.find(".comments-modal-img-i").empty().append(`
    <p class="comments-modal-filename">${comment.file}</p>
    <p class="icon-calendar">${comment.imageDateAvailable}</p>
  `);
  modalViewComment.find(".comments-modal-body").html(comment.content);

  const validBtn = modalViewComment.find(".comments-modal-validate");
  if (comment.isPending) {
    validBtn.show();
    $("#commentsModalValidate")
      .off("click")
      .on("click", function () {
        validateComment([id]);
        closeModalViewComment();
      });
  } else {
    validBtn.hide();
  }

  $("#commentsModalDelete")
    .off("click")
    .on("click", function () {
      deleteComment([id]);
      closeModalViewComment();
    });

  modalViewComment.fadeIn();
}

function closeModalViewComment() {
  modalViewComment.fadeOut();
  $("#commentsModalValidate").off("click");
  $("#commentsModalDelete").off("click");
}

function validateComment(id: (string | number)[]) {
  const idLenght = id.length ?? 1;

  $.ajax({
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
      _data: import("../../../../openapi/client/schema").operations["commentValidate"]["responses"][200]["content"]["application/json"],
    ) {
      $.alert({
        ...{
          title: idLenght > 1 ? str_comments_validated : str_comment_validated,
          content: "",
        },
        ...jConfirm_alert_options,
      });
      getComments(commentsParams);
    },
    error: function (e: JQuery.jqXHR) {
      console.log(e);
      $.alert({
        ...{
          title: str_an_error_has,
          content: "",
        },
        ...jConfirm_warning_options,
      });
    },
  });
}

function deleteComment(id: (string | number)[]) {
  const idLenght = id.length ?? 1;

  $.confirm({
    title:
      idLenght > 1
        ? str_deletes.replace("%d", String(idLenght))
        : str_delete.replace("%s", String(id)),
    draggable: false,
    titleClass: "jconfirmDeleteConfirm",
    theme: "modern",
    content: "",
    animation: "zoom",
    boxWidth: "30%",
    useBootstrap: false,
    type: "red",
    animateFromElement: false,
    backgroundDismiss: true,
    typeAnimated: false,
    buttons: {
      confirm: {
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          $.ajax({
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
              _data: import("../../../../openapi/client/schema").operations["commentDelete"]["responses"][200]["content"]["application/json"],
            ) {
              getComments(commentsParams);
            },
            error: function (e: JQuery.jqXHR) {
              console.log(e);
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
  $(".comment").removeClass("comment-selected");
  $(".comment-select-checkbox")
    .removeClass("icon-ok-circled")
    .addClass("icon-circle-empty");

  commentsSelected = [];
  commentsUpdateSelection();
}

function commentsSelectAll() {
  $(".comment").addClass("comment-selected");
  $(".comment-select-checkbox")
    .removeClass("icon-circle-empty")
    .addClass("icon-ok-circled");

  commentsSelected = [];
  $(".comment-selected").each((i, el) => {
    const id = $(el).attr("id")!;
    commentsSelected.push(id);
  });
  commentsUpdateSelection();
}

function commentsInvertSelect() {
  $(".comment").toggleClass("comment-selected");
  $(".comment-select-checkbox")
    .toggleClass("icon-ok-circled")
    .toggleClass("icon-circle-empty");

  commentsSelected = [];
  $(".comment-selected").each((i, el) => {
    const id = $(el).attr("id")!;
    commentsSelected.push(id);
  });
  commentsUpdateSelection();
}

function commentsUpdateSelection() {
  if (commentsSelected.length === 0) {
    $("#commentsSelection").hide();
    $("#commentsNoSelection").show();
    $(".comments-selected-remove").off("click");
    $("#ValisateSelectionMode").off("click");
    $("#DeleteSelectionMode").off("click");

    return;
  }

  commentsSelectedArea.empty();
  let count = 0;
  commentsSelected.forEach((id) => {
    if (count === 5) {
      commentsSelectedOthers.text(
        str_and_others.replace(/%s/g, String(commentsSelected.length - 5)),
      );
      return;
    }
    commentsSelectedOthers.text("");
    const item = commentsSelectedList.replace(/%d/g, String(id));
    commentsSelectedArea.append(item);
    count++;
  });

  $(".comments-selected-remove")
    .off("click")
    .on("click", function () {
      const id = $(this).attr("id")!.split("_")[1];
      if (!id) return;
      $(`#${id} .comment-content`).trigger("click");
    });

  $("#ValisateSelectionMode")
    .off("click")
    .on("click", function () {
      validateComment(commentsSelected);
      commentsUnselectAll();
    });

  $("#DeleteSelectionMode")
    .off("click")
    .on("click", function () {
      deleteComment(commentsSelected);
      commentsUnselectAll();
    });

  $("#commentsNoSelection").hide();
  $("#commentsSelection").show();
}

function commentsClearFilters() {
  delete commentsParams.author_id;
  delete commentsParams.image_id;
  delete commentsParams.search;
  delete commentsParams.f_min_date;
  delete commentsParams.f_max_date;
  getComments(commentsParams);
}
