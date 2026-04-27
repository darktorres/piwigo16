declare var unit_MB: string;

function displayResponse(domElem: JQuery[], values: string[], mDivs: NodeListOf<Element>, mValues: Record<string, string>): void {
    for (let index = 0; index < domElem.length; index++) {
        domElem[index].html(unit_MB.replace('%s', values[index]));
    }
    let mDivName: string;
    for (let index = 0; index < mDivs.length; index++) {
        mDivName = (mDivs[index] as HTMLElement).getAttribute('name') ?? '';
        (mDivs[index] as HTMLElement).title = unit_MB.replace('%s', mValues[mDivName]);
    }
    $('.cache-lastCalculated-value').html(no_time_elapsed);
}

$(document).ready(function () {
    $('.refresh-cache-size').on('click', function () {
        $(this).find('.refresh-icon').addClass('animate-spin');
        return new Promise<void>((res, rej) => {
            jQuery.ajax({
                url: 'ws.php?format=json&method=pwg.getCacheSize',
                type: 'POST',
                data: { param: 'test_param', service: 'test_service' },
                success(raw_data: string) {
                    const data = jQuery.parseJSON(raw_data) as any;
                    if (data.stat === 'ok') {
                        res();
                        const domElemToRefresh: JQuery[] = [
                            $('.cache-size-value'),
                            $('.multiple-pictures-sizes'),
                            $('.multiple-compiledTemplate-sizes'),
                        ];
                        const domElemValues: string[] = [
                            data.result.infos[0].value,
                            data.result.infos[1].value.all,
                            data.result.infos[2].value,
                        ];
                        for (let i = 0; i < domElemValues.length; i++) {
                            domElemValues[i] = (domElemValues[i] as any / 1024 / 1024).toFixed(2);
                        }
                        const multipleSizes = $('.delete-check-container').children('.delete-size-check');
                        const multipleSizesValues: Record<string, string> = data.result.infos[1].value;
                        for (const key of Object.keys(multipleSizesValues)) {
                            (multipleSizesValues as any)[key] = ((multipleSizesValues as any)[key] / 1024 / 1024).toFixed(2);
                        }
                        displayResponse(domElemToRefresh, domElemValues, multipleSizes.toArray() as any, multipleSizesValues);
                        $('.animate-spin').removeClass('animate-spin');
                    } else {
                        rej(data);
                    }
                },
                error(message) { rej(message); console.log(message); },
            });
        });
    });
});

export {};
