import { initModule } from './moduleInit.js';
import DataTable from 'datatables.net';
import { pwgConfirm } from './pwgConfirm.js';
import { PwgWS } from './pwgws.js';

interface RatingUserConfig {
    nbElements?: number;
    rootUrl?: string;
    titleMsg?: string;
    confirmMsg?: string;
    cancelMsg?: string;
}

export function init(cfg: RatingUserConfig): void {
    const { nbElements = 0, rootUrl = '', titleMsg = 'Are you sure?', confirmMsg = 'Yes', cancelMsg = 'No' } = cfg;

    const h1 = document.querySelector('h1');
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbElements + '</span>');

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const oTable = new (DataTable as any)('#rateTable', {
        dom: '<"dtBar"filp>rt<"dtBar"ilp>',
        pageLength: 100,
        lengthMenu: [[25, 50, 100, 500, -1], [25, 50, 100, 500, 'All']],
        order: [],
        autoWidth: false,
        columnDefs: [
            { targets: ['dtc_user'], type: 'string' },
            { targets: ['dtc_date'], orderSequence: ['desc', 'asc'], type: 'string' },
            { targets: ['dtc_stat'], orderSequence: ['desc', 'asc'], searchable: false, type: 'numeric' },
            { targets: ['dtc_rate'], orderSequence: ['desc', 'asc'], searchable: false, type: 'html' },
            { targets: ['dtc_del'], orderable: false, searchable: false, type: 'string' },
        ],
    });

    function uidFromCell(cell: HTMLElement): { uid: string; aid: string } {
        let tr = cell;
        while (tr.nodeName !== 'TR') tr = tr.parentNode as HTMLElement;
        return JSON.parse((tr as HTMLElement).dataset.usr ?? '{}') as { uid: string; aid: string };
    }

    const rateTable = document.getElementById('rateTable');
    if (rateTable) {
        rateTable.addEventListener('click', function (e) {
            const delBtn = (e.target as HTMLElement).closest<HTMLElement>('.del');
            if (!delBtn) return;
            e.preventDefault();
            const trEl = delBtn.closest<HTMLElement>('tr');
            const usr_name = trEl ? (trEl.querySelector<HTMLElement>('.usr')?.innerHTML ?? '') : '';

            pwgConfirm({
                title: titleMsg.replace('%s', usr_name),
                buttons: {
                    confirm: {
                        text: confirmMsg,
                        btnClass: 'btn-red',
                        action: function () {
                            const cell = (e.target as HTMLElement).parentNode as HTMLElement;
                            let trNode = cell;
                            while (trNode.nodeName !== 'TR') trNode = trNode.parentNode as HTMLElement;
                            const anim = trNode.animate([{ opacity: 1 }, { opacity: 0.4 }], { duration: 1000, fill: 'forwards' });
                            const data = uidFromCell(cell);
                            (new PwgWS(rootUrl)).callService('pwg.rates.delete', { user_id: data.uid, anonymous_id: data.aid }, {
                                method: 'POST',
                                onFailure: function (num: number, text: string) {
                                    anim.cancel();
                                    trNode.style.opacity = '1';
                                    alert(num + ' ' + text);
                                },
                                onSuccess: function (result: unknown) {
                                    if (result) oTable.row(trNode).remove().draw();
                                    else alert(String(result));
                                },
                            });
                        },
                    },
                    cancel: { text: cancelMsg },
                },
            });
        });
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
