import { getPageData } from './page-data';
import { config } from './config';

interface CommentsPageData {
    pwg_token: string;
    str_yes_delete_confirmation: string;
    str_no_delete_confirmation: string;
    str_delete: string;
    str_deletes: string;
    str_no_comments_selected: string;
    str_an_error_has: string;
    str_comment_validated: string;
    str_comments_validated: string;
    str_and_others: string;
}

const {
    pwg_token,
    str_yes_delete_confirmation,
    str_no_delete_confirmation,
    str_delete,
    str_deletes,
    str_no_comments_selected,
    str_an_error_has,
    str_comment_validated,
    str_comments_validated,
    str_and_others,
} = getPageData<CommentsPageData>();

const commentsContainer = document.getElementById('comments')!;
const advancedFilters = document.getElementById('advancedFilters') as HTMLElement;
const switchMode = document.getElementById('toggleSelectionMode') as HTMLInputElement;
const commentContainer = document.getElementById('commentContainer') as HTMLElement;
const commentsAll = document.getElementById('commentsAll') as HTMLElement;
const commentsValidated = document.getElementById('commentsValidated') as HTMLElement;
const commentsPending = document.getElementById('commentsPending') as HTMLElement;
const commentsList = document.getElementById('commentsList') as HTMLElement;
const commentsNb = document.querySelectorAll<HTMLElement>('#commentsNb a');
const filterAuthor = document.getElementById('filter_author') as HTMLSelectElement;
const filterDateStart = document.getElementById('filter_date_start') as HTMLInputElement;
const filterDateEnd = document.getElementById('filter_date_end') as HTMLInputElement;
const commentsSelectController = document.getElementById('commentsSelectController') as HTMLElement;
const tabFilters = document.getElementById('tabFilters') as HTMLElement;
const commentsSelectedArea = document.getElementById('commentsSelected') as HTMLElement;
const commentsSelectedOthers = document.getElementById('commentsSelectedOthers') as HTMLElement;
const modalViewComment = document.getElementById('modalViewComment') as HTMLElement;

const commentsPaginElipsis = '<span>...</span>';
const commentsPaginItems = '<a id="comments_page_%d" class="comments-paging" data-page="%d">%d</a>';
const commentsPaginItemsCurrent =
    '<a id="comments_page_%d" class="comments-paging comment-paging-current" data-page="%d">%d</a>';
const commentsOptionsFiltersAuthor = '<option value="" selected="">--</option>';
const commentsSelectedList =
    '<div class="comments-selected-item"><a class="icon-cancel comments-selected-remove" id="deletecomment_%d"></a> <p>#%d</p></div>';

let commentsState: Record<string, any> = {};
const commentsParams: Record<string, any> = { status: 'all', page: 0, per_page: 5 };
let updateAuthorId = true;
let searchTimeOut: ReturnType<typeof setTimeout> | null = null;
let selectionMode = false;
let commentsSelected: any[] = [];
let selectionAbort: AbortController | null = null;
let filtersAbort: AbortController | null = null;

document.addEventListener('DOMContentLoaded', () => {
    document
        .getElementById('commentFilters')
        ?.addEventListener('click', function (this: HTMLElement) {
            this.classList.toggle('advanced-filter-open');
            advancedFilters.style.display = advancedFilters.style.display === 'none' ? '' : 'none';
        });

    switchMode.addEventListener('change', function (this: HTMLInputElement) {
        const contentSelectMode = document.getElementById('contentSelectMode') as HTMLElement;
        contentSelectMode.style.display = contentSelectMode.style.display === 'none' ? '' : 'none';
        document.getElementById('headerSelectMode')?.classList.toggle('selection-mode');
        contentSelectMode.classList.toggle('selection-mode');
        commentContainer.classList.toggle('active');

        if (!commentContainer.classList.contains('active')) {
            selectionMode = false;
            document.querySelectorAll<HTMLElement>('.comment-select-checkbox').forEach((el) => {
                el.style.display = 'none';
            });
            document.querySelectorAll<HTMLElement>('.comment-buttons').forEach((el) => {
                el.style.display = '';
            });
            commentsSelectController.classList.remove('show');
            tabFilters.style.display = '';
            commentsUnselectAll();
        } else {
            selectionMode = true;
            document.querySelectorAll<HTMLElement>('.comment-select-checkbox').forEach((el) => {
                el.style.display = '';
            });
            document.querySelectorAll<HTMLElement>('.comment-buttons').forEach((el) => {
                el.style.display = 'none';
            });
            tabFilters.style.display = 'none';
            commentsSelectController.classList.add('show');
        }
    });

    document.getElementById('selectAll')?.addEventListener('click', () => commentsSelectAll());
    document.getElementById('selectNone')?.addEventListener('click', () => commentsUnselectAll());
    document
        .getElementById('selectInvert')
        ?.addEventListener('click', () => commentsInvertSelect());

    document.querySelectorAll<HTMLElement>('.tab-filters input').forEach((el) => {
        el.addEventListener('change', function (this: HTMLInputElement) {
            commentsParams.status = this.dataset['status'];
            commentsParams.page = 0;
            getComments(commentsParams);
        });
    });

    commentsNb.forEach((el) => {
        el.addEventListener('click', function (this: HTMLElement) {
            updateNbComments(this.textContent ?? '');
            commentsParams.page = 0;
            getComments(commentsParams);
        });
    });

    document
        .getElementById('closeModalViewComment')
        ?.addEventListener('click', () => closeModalViewComment());

    document
        .getElementById('commentSearchInput')
        ?.addEventListener('input', function (this: HTMLInputElement) {
            if (searchTimeOut) clearTimeout(searchTimeOut);
            searchTimeOut = setTimeout(() => {
                const search = this.value;
                delete commentsParams.author_id;
                delete commentsParams.f_min_date;
                delete commentsParams.f_max_date;
                commentsParams.search = search;
                getComments(commentsParams);
            }, 300);
        });

    document
        .getElementById('commentsResetFilters')
        ?.addEventListener('click', () => commentsClearFilters());

    window.addEventListener('keydown', (e: KeyboardEvent) => {
        if (e.key === 'Escape') closeModalViewComment();
    });

    commentsParams.per_page = window.localStorage.getItem('adminCommentsNB') ?? 5;
    updateNbComments(commentsParams.per_page);
    getComments(commentsParams);
});

function pwgFetch(url: string, data: Record<string, any>): Promise<any> {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v)) v.forEach((item) => body.append(k + '[]', String(item)));
        else body.append(k, String(v ?? ''));
    }
    return fetch(url, { method: 'POST', body }).then((r) => r.json());
}

function getComments(params: any) {
    const query = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) query.append(k, String(v ?? ''));
    fetch(config.wsUrl + 'format=json&method=pwg.userComments.getList&' + query.toString())
        .then((r) => r.json())
        .then((data) => {
            if (data.stat === 'ok') {
                commentsState = { ...data.result };
                commentsDisplaySummary(data.result.summary);
                displayComments(data.result.comments);
                commentsDiplayPagination(data.result.paging);
                commentsDisplayFilters(data.result.filters);
                delete commentsParams.search;
            }
        })
        .catch((e) => {
            console.log(e);
            window.alert(str_an_error_has);
        });
}

function commentsDisplaySummary(summary: any) {
    commentsAll.textContent = summary.all_comments;
    commentsValidated.textContent = summary.validated;
    commentsPending.textContent = summary.pending;
}

function displayComments(comments: any) {
    commentsList.innerHTML = '';
    const template = document.querySelector<HTMLElement>('.comment-template')!;
    comments.forEach((comment: any) => {
        const clone = template.cloneNode(true) as HTMLElement;
        clone.classList.remove('comment-template');
        clone.classList.add('comment');
        clone.id = String(comment.id);
        clone.querySelector<HTMLImageElement>('.comment-img')!.src = comment.medium_url;
        const raw_length = comment.raw_content.length;
        const preview =
            raw_length > 50 ? comment.raw_content.substring(0, 50) + '...' : comment.raw_content;
        clone.querySelector<HTMLElement>('.comment-msg')!.textContent = '"' + preview + '"';
        clone.querySelector<HTMLElement>('.comment-author-name')!.textContent = comment.author;
        clone.querySelector<HTMLElement>('.comment-datetime')!.textContent = comment.date;
        clone.querySelector<HTMLElement>('.comment-delete')!.dataset['idx'] = String(comment.id);
        clone.querySelector<HTMLElement>('.comment-validate')!.dataset['idx'] = String(comment.id);
        clone.querySelector<HTMLElement>('.comment-content')!.dataset['idx'] = String(comment.id);
        clone.querySelector<HTMLElement>('.comment-hash')!.textContent = `#${comment.id}`;
        clone.querySelector<HTMLInputElement>('.comment-select-checkbox')!.value = String(
            comment.id
        );
        clone.querySelector<HTMLAnchorElement>('.comment-link')!.href = comment.admin_link;
        const authorIcons = clone.querySelectorAll<HTMLElement>('.comment-author-icon');
        const iconClass: Record<string, string> = {
            guest: 'icon-user-secret icon-yellow',
            webmaster: 'icon-user icon-purple',
            admin: 'icon-user icon-green',
            main_user: 'icon-king icon-blue',
        };
        authorIcons.forEach((icon) =>
            icon.classList.add(
                ...(iconClass[comment.author_status] ?? 'icon-user icon-yellow').split(' ')
            )
        );
        if (comment.is_pending)
            clone.querySelector<HTMLElement>('.comment-validate')!.style.display = '';
        else
            clone
                .querySelector<HTMLElement>('.comment-container')
                ?.classList.add('comment-validated');
        commentsList.append(clone);
    });

    document.querySelectorAll<HTMLElement>('.comment-delete').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            deleteComment([el.dataset['idx']]);
        });
    });
    document.querySelectorAll<HTMLElement>('.comment-validate').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            validateComment([el.dataset['idx']]);
        });
    });
    document.querySelectorAll<HTMLElement>('.comment-content').forEach((el) => {
        el.addEventListener('click', () => {
            const id = el.dataset['idx'];
            if (selectionMode) {
                const checkbox = el.querySelector<HTMLElement>('.comment-select-checkbox')!;
                const commentEl = document.getElementById(id!)!;
                if (checkbox.classList.contains('icon-circle-empty')) {
                    checkbox.classList.replace('icon-circle-empty', 'icon-ok-circled');
                    commentEl.classList.add('comment-selected');
                    commentsSelected.push(id);
                } else {
                    checkbox.classList.replace('icon-ok-circled', 'icon-circle-empty');
                    commentEl.classList.remove('comment-selected');
                    commentsSelected = commentsSelected.filter((idx) => idx !== id);
                }
                commentsUpdateSelection();
                return;
            }
            showModalViewComment(id);
        });
    });
}

function commentsDiplayPagination(paging: any) {
    const container = document.querySelector<HTMLElement>('.pagination-item-container')!;
    container.innerHTML = '';

    const makePageEl = (html: string, cls?: string): HTMLElement => {
        const div = document.createElement('div');
        div.innerHTML = html;
        const el = div.firstElementChild as HTMLElement;
        if (cls) el.classList.add(cls);
        return el;
    };

    if (paging.total_pages === 0) {
        container.append(makePageEl(commentsPaginItems.replace(/%d/g, '1'), 'actual'));
    } else if (paging.total_pages <= 2) {
        Array.from({ length: paging.total_pages + 1 }, (_, i) => {
            container.insertAdjacentHTML(
                'beforeend',
                commentsPaginItems.replace(/%d/g, String(i + 1))
            );
        });
        document.getElementById(`comments_page_${paging.page + 1}`)?.classList.add('actual');
    } else {
        const pageOne = commentsPaginItems.replace(/%d/g, '1');
        const pageLast = commentsPaginItems.replace(/%d/g, String(paging.total_pages + 1));
        const pageCurrent = commentsPaginItemsCurrent.replace(/%d/g, String(paging.page + 1));
        switch (paging.page) {
            case 0:
                container.insertAdjacentHTML(
                    'beforeend',
                    pageCurrent + commentsPaginElipsis + pageLast
                );
                break;
            case paging.total_pages:
                container.insertAdjacentHTML(
                    'beforeend',
                    pageOne + commentsPaginElipsis + pageCurrent
                );
                break;
            default:
                container.insertAdjacentHTML(
                    'beforeend',
                    pageOne + commentsPaginElipsis + pageCurrent + commentsPaginElipsis + pageLast
                );
        }

        document.querySelectorAll<HTMLElement>('.pagination-arrow').forEach((arrow) => {
            arrow.classList.remove('unavailable');
            arrow.addEventListener('click', () => {
                let newPage = commentsParams.page;
                newPage += arrow.classList.contains('left') ? -1 : 1;
                if (newPage === -1 || newPage > commentsState.paging.total_pages) return;
                commentsParams.page = newPage;
                getComments(commentsParams);
            });
        });
    }

    document.querySelectorAll<HTMLElement>('.comments-paging').forEach((el) => {
        el.addEventListener('click', function (this: HTMLElement) {
            commentsParams.page = Number(this.dataset['page']) - 1;
            getComments(commentsParams);
        });
    });
}

function commentsDisplayFilters(filters: any) {
    filtersAbort?.abort();
    filtersAbort = new AbortController();
    const { signal } = filtersAbort;

    if (updateAuthorId) commentsDisplayAuthors(filters.nb_authors);
    updateAuthorId = true;

    const minDate = filters.started_at?.split(' ')[0] ?? '';
    const maxDate = filters.ended_at?.split(' ')[0] ?? '';
    filterDateStart.value = minDate;
    filterDateStart.setAttribute('min', minDate);
    filterDateStart.setAttribute('max', maxDate);
    filterDateEnd.value = maxDate;
    filterDateEnd.setAttribute('max', maxDate);
    filterDateEnd.setAttribute('min', minDate);

    filterDateStart.addEventListener(
        'change',
        function (this: HTMLInputElement) {
            const min = this.value;
            if (!min) delete commentsParams.f_min_date;
            else commentsParams.f_min_date = min;
            filterDateEnd.setAttribute('min', min);
            commentsParams.page = 0;
            getComments(commentsParams);
        },
        { signal }
    );

    filterDateEnd.addEventListener(
        'change',
        function (this: HTMLInputElement) {
            const max = this.value;
            if (!max) delete commentsParams.f_max_date;
            else commentsParams.f_max_date = max;
            filterDateStart.setAttribute('max', max);
            commentsParams.page = 0;
            getComments(commentsParams);
        },
        { signal }
    );
}

function commentsDisplayAuthors(nb_authors: any) {
    filterAuthor.innerHTML = commentsOptionsFiltersAuthor;
    nb_authors.forEach((a: any) => {
        filterAuthor.insertAdjacentHTML(
            'beforeend',
            `<option value="${a.author_id}">${a.author} (${a.nb_authors})</option>`
        );
    });

    filtersAbort?.abort();
    filtersAbort = new AbortController();
    const { signal } = filtersAbort;
    filterAuthor.addEventListener(
        'change',
        function (this: HTMLSelectElement) {
            const authorId = this.value;
            if (!authorId) delete commentsParams.author_id;
            else commentsParams.author_id = authorId;
            commentsParams.page = 0;
            updateAuthorId = false;
            getComments(commentsParams);
        },
        { signal }
    );
}

function updateNbComments(nb: any) {
    document
        .querySelectorAll<HTMLElement>('.comments-paging-link')
        .forEach((el) => el.classList.remove('selected-pagination'));
    document.getElementById(`pagination-per-page-${nb}`)?.classList.add('selected-pagination');
    commentsParams.per_page = nb;
    window.localStorage.setItem('adminCommentsNB', String(nb));
}

function showModalViewComment(id: any) {
    const comment = (commentsState as any).comments.find((c: any) => c.id === id) ?? null;
    if (!comment) return;
    const item = document.getElementById(id)!;
    modalViewComment.querySelector<HTMLElement>('.comment-datetime')!.textContent = comment.date;
    modalViewComment.querySelector('.comment-author')?.remove();
    modalViewComment
        .querySelector('.comments-modal-infos')!
        .prepend(item.querySelector('.comment-author')!.cloneNode(true));
    modalViewComment.querySelector<HTMLImageElement>('.comments-modal-img')!.src =
        comment.medium_url;
    const imgInfo = modalViewComment.querySelector<HTMLElement>('.comments-modal-img-i')!;
    imgInfo.innerHTML = `<p class="comments-modal-filename">${comment.file}</p><p class="icon-calendar">${comment.image_date_available}</p>`;
    modalViewComment.querySelector<HTMLElement>('.comments-modal-body')!.innerHTML =
        comment.content;

    const validBtn = modalViewComment.querySelector<HTMLElement>('.comments-modal-validate')!;
    const validateBtn = document.getElementById('commentsModalValidate')!;
    const deleteBtn = document.getElementById('commentsModalDelete')!;
    const newValidateBtn = validateBtn.cloneNode(true) as HTMLElement;
    const newDeleteBtn = deleteBtn.cloneNode(true) as HTMLElement;
    validateBtn.replaceWith(newValidateBtn);
    deleteBtn.replaceWith(newDeleteBtn);

    if (comment.is_pending) {
        validBtn.style.display = '';
        newValidateBtn.addEventListener('click', () => {
            validateComment([id]);
            closeModalViewComment();
        });
    } else {
        validBtn.style.display = 'none';
    }
    newDeleteBtn.addEventListener('click', () => {
        deleteComment([id]);
        closeModalViewComment();
    });
    modalViewComment.style.display = '';
}

function closeModalViewComment() {
    modalViewComment.style.display = 'none';
}

function validateComment(id: any) {
    const idLength = id.length ?? 1;
    pwgFetch(config.wsUrl + 'format=json&method=pwg.userComments.validate', {
        comment_id: id,
        pwg_token,
    })
        .then((res) => {
            if (res.stat === 'ok') {
                window.alert(idLength > 1 ? str_comments_validated : str_comment_validated);
                getComments(commentsParams);
                return;
            }
            window.alert(str_an_error_has);
        })
        .catch((e) => {
            console.log(e);
            window.alert(str_an_error_has);
        });
}

function deleteComment(id: any) {
    const idLength = id.length ?? 1;
    const msg =
        idLength > 1
            ? str_deletes.replace('%d', String(idLength))
            : str_delete.replace('%s', String(id));
    if (!window.confirm(msg)) return;
    pwgFetch(config.wsUrl + 'format=json&method=pwg.userComments.delete', {
        comment_id: id,
        pwg_token,
    })
        .then((res) => {
            if (res.stat === 'ok') getComments(commentsParams);
        })
        .catch((e) => console.log(e));
}

function commentsUnselectAll() {
    document
        .querySelectorAll<HTMLElement>('.comment')
        .forEach((el) => el.classList.remove('comment-selected'));
    document.querySelectorAll<HTMLElement>('.comment-select-checkbox').forEach((el) => {
        el.classList.remove('icon-ok-circled');
        el.classList.add('icon-circle-empty');
    });
    commentsSelected = [];
    commentsUpdateSelection();
}

function commentsSelectAll() {
    document
        .querySelectorAll<HTMLElement>('.comment')
        .forEach((el) => el.classList.add('comment-selected'));
    document.querySelectorAll<HTMLElement>('.comment-select-checkbox').forEach((el) => {
        el.classList.remove('icon-circle-empty');
        el.classList.add('icon-ok-circled');
    });
    commentsSelected = [];
    document
        .querySelectorAll<HTMLElement>('.comment-selected')
        .forEach((el) => commentsSelected.push(el.id));
    commentsUpdateSelection();
}

function commentsInvertSelect() {
    document
        .querySelectorAll<HTMLElement>('.comment')
        .forEach((el) => el.classList.toggle('comment-selected'));
    document.querySelectorAll<HTMLElement>('.comment-select-checkbox').forEach((el) => {
        el.classList.toggle('icon-ok-circled');
        el.classList.toggle('icon-circle-empty');
    });
    commentsSelected = [];
    document
        .querySelectorAll<HTMLElement>('.comment-selected')
        .forEach((el) => commentsSelected.push(el.id));
    commentsUpdateSelection();
}

function commentsUpdateSelection() {
    selectionAbort?.abort();
    selectionAbort = new AbortController();
    const { signal } = selectionAbort;

    if (commentsSelected.length === 0) {
        document.getElementById('commentsSelection')!.style.display = 'none';
        document.getElementById('commentsNoSelection')!.style.display = '';
        return;
    }

    commentsSelectedArea.innerHTML = '';
    commentsSelectedOthers.textContent = '';
    commentsSelected.forEach((id, count) => {
        if (count === 5) {
            commentsSelectedOthers.textContent = str_and_others.replace(
                /%s/g,
                String(commentsSelected.length - 5)
            );
            return;
        }
        commentsSelectedArea.insertAdjacentHTML(
            'beforeend',
            commentsSelectedList.replace(/%d/g, String(id))
        );
    });

    document.querySelectorAll<HTMLElement>('.comments-selected-remove').forEach((el) => {
        el.addEventListener(
            'click',
            function (this: HTMLElement) {
                const id = this.id.split('_')[1];
                if (!id) return;
                document
                    .getElementById(id)
                    ?.querySelector<HTMLElement>('.comment-content')
                    ?.click();
            },
            { signal }
        );
    });

    document.getElementById('ValisateSelectionMode')?.addEventListener(
        'click',
        () => {
            validateComment(commentsSelected);
            commentsUnselectAll();
        },
        { signal }
    );

    document.getElementById('DeleteSelectionMode')?.addEventListener(
        'click',
        () => {
            deleteComment(commentsSelected);
            commentsUnselectAll();
        },
        { signal }
    );

    document.getElementById('commentsNoSelection')!.style.display = 'none';
    document.getElementById('commentsSelection')!.style.display = '';
}

function commentsClearFilters() {
    delete commentsParams.author_id;
    delete commentsParams.image_id;
    delete commentsParams.search;
    delete commentsParams.f_min_date;
    delete commentsParams.f_max_date;
    getComments(commentsParams);
}

export {};
