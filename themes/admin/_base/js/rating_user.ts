import '../css/pages/rating_user.css';
import DataTable from 'datatables.net';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import { getPageData } from './page-data';

interface RatingUserPageData {
    nb_elements: number;
    root_url: string;
    str_delete_ratings_confirm: string;
}

declare class PwgWS {
    constructor(rootUrl: string);
    callService(
        method: string,
        params: Record<string, unknown>,
        opts: {
            method?: string;
            onFailure?: (num: number, text: string) => void;
            onSuccess?: (result: unknown) => void;
        }
    ): void;
}

const pageData = getPageData<RatingUserPageData>();

document
    .querySelector('h1')
    ?.insertAdjacentHTML('beforeend', `<span class="badge-number">${pageData.nb_elements}</span>`);

const oTable = new DataTable('#rateTable', {
    pageLength: 100,
    lengthMenu: [
        [25, 50, 100, 500, -1],
        [25, 50, 100, 500, 'All'],
    ],
    order: [],
    autoWidth: false,
    columnDefs: [
        { targets: '.dtc_user', type: 'string' },
        { targets: '.dtc_date', orderSequence: ['desc', 'asc'], type: 'string' },
        { targets: '.dtc_stat', orderSequence: ['desc', 'asc'], searchable: false, type: 'num' },
        { targets: '.dtc_rate', orderSequence: ['desc', 'asc'], searchable: false, type: 'html' },
        { targets: '.dtc_del', orderable: false, searchable: false },
    ],
});

function uidFromCell(cell: HTMLElement): { uid: number; aid: string } {
    let tr: Node = cell;
    while (tr.nodeName !== 'TR') {
        tr = tr.parentNode as Node;
    }
    return JSON.parse((tr as HTMLElement).getAttribute('data-usr') ?? 'null') as {
        uid: number;
        aid: string;
    };
}

document.getElementById('rateTable')?.addEventListener('click', (e) => {
    const target = e.target as HTMLElement | null;
    const delBtn = target?.closest('.del') as HTMLElement | null;
    if (!delBtn) return;
    e.preventDefault();
    const tr = delBtn.closest('tr');
    if (!tr) return;
    const usrName = tr.querySelector('.usr')?.innerHTML ?? '';
    if (!window.confirm(pageData.str_delete_ratings_confirm.replace('%s', usrName))) return;
    const cell = delBtn.parentElement as HTMLElement;
    const data = uidFromCell(cell);
    tr.style.opacity = '0.4';
    new PwgWS(pageData.root_url).callService(
        'pwg.rates.delete',
        { user_id: data.uid, anonymous_id: data.aid },
        {
            method: 'POST',
            onFailure: (num, text) => {
                tr.style.opacity = '1';
                alert(num + ' ' + text);
            },
            onSuccess: (result) => {
                if (result !== null && result !== undefined && result !== false) {
                    oTable.row(tr).remove().draw();
                } else {
                    alert(String(result));
                }
            },
        }
    );
});

(window as unknown as Window & { DataTable: typeof DataTable }).DataTable = DataTable;

export {};
