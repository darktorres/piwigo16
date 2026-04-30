import { getPageData } from './page-data';

interface MaintenancePageData {
    unit_MB: string;
    no_time_elapsed: string;
}

const { unit_MB, no_time_elapsed } = getPageData<MaintenancePageData>();

function displayResponse(domElem: HTMLElement[], values: string[], mDivs: NodeListOf<Element>, mValues: Record<string, string>): void {
    for (let index = 0; index < domElem.length; index++) {
        domElem[index].innerHTML = unit_MB.replace('%s', values[index]);
    }
    for (let index = 0; index < mDivs.length; index++) {
        const mDivName = (mDivs[index] as HTMLElement).getAttribute('name') ?? '';
        (mDivs[index] as HTMLElement).title = unit_MB.replace('%s', mValues[mDivName]);
    }
    document.querySelectorAll<HTMLElement>('.cache-lastCalculated-value')
        .forEach(el => { el.innerHTML = no_time_elapsed; });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.refresh-cache-size').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.querySelector('.refresh-icon')?.classList.add('animate-spin');
            fetch('ws.php?format=json&method=pwg.getCacheSize', {
                method: 'POST',
                body: new URLSearchParams({ param: 'test_param', service: 'test_service' }),
            })
                .then(r => r.json())
                .then((data: any) => {
                    if (data.stat !== 'ok') return;
                    const domElemToRefresh: HTMLElement[] = [
                        document.querySelector<HTMLElement>('.cache-size-value')!,
                        document.querySelector<HTMLElement>('.multiple-pictures-sizes')!,
                        document.querySelector<HTMLElement>('.multiple-compiledTemplate-sizes')!,
                    ];
                    const domElemValues: string[] = [
                        data.result.infos[0].value,
                        data.result.infos[1].value.all,
                        data.result.infos[2].value,
                    ];
                    for (let i = 0; i < domElemValues.length; i++) {
                        domElemValues[i] = (Number(domElemValues[i]) / 1024 / 1024).toFixed(2);
                    }
                    const multipleSizes = document.querySelectorAll('.delete-check-container .delete-size-check');
                    const multipleSizesValues: Record<string, string> = data.result.infos[1].value;
                    for (const key of Object.keys(multipleSizesValues)) {
                        (multipleSizesValues as any)[key] = (Number((multipleSizesValues as any)[key]) / 1024 / 1024).toFixed(2);
                    }
                    displayResponse(domElemToRefresh, domElemValues, multipleSizes, multipleSizesValues);
                    document.querySelectorAll('.animate-spin').forEach(el => el.classList.remove('animate-spin'));
                })
                .catch((message) => console.log(message));
        });
    });
});

export {};
