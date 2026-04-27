let gRatingOptions: Record<string, unknown>;
let gRatingButtons: HTMLCollectionOf<HTMLInputElement>;
let gUserRating: string;

function makeNiceRatingForm(options: Record<string, unknown>): void {
    gRatingOptions = options;
    const form = document.getElementById('rateForm');
    if (!form) return;

    gRatingButtons = form.getElementsByTagName('input');
    gUserRating = '';
    for (let i = 0; i < gRatingButtons.length; i++) {
        if (gRatingButtons[i].type === 'button') {
            gUserRating = gRatingButtons[i].value;
            break;
        }
    }

    for (let i = 0; i < gRatingButtons.length; i++) {
        const rateButton = gRatingButtons[i];
        (rateButton as any).initialRateValue = rateButton.value;
        try { rateButton.type = 'button'; } catch (e) {}

        rateButton.value = ' ';
        rateButton.style.marginLeft = '0';
        rateButton.style.marginRight = '0';

        if (i !== gRatingButtons.length - 1 && rateButton.nextSibling?.nodeType === 3) {
            rateButton.parentNode!.removeChild(rateButton.nextSibling);
        }
        if (i > 0 && rateButton.previousSibling?.nodeType === 3) {
            rateButton.parentNode!.removeChild(rateButton.previousSibling);
        }

        pwgAddEventListener(rateButton, 'click', updateRating);
        pwgAddEventListener(rateButton, 'mouseout', () => { updateRatingStarDisplay(gUserRating); });
        pwgAddEventListener(rateButton, 'mouseover', (e: Event) => {
            const target = (e as MouseEvent).target as any;
            updateRatingStarDisplay(target.initialRateValue);
        });
    }
    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string): void {
    for (let i = 0; i < gRatingButtons.length; i++) {
        gRatingButtons[i].className = (userRating !== '' && userRating >= (gRatingButtons[i] as any).initialRateValue)
            ? 'rateButtonStarFull'
            : 'rateButtonStarEmpty';
    }
}

function updateRating(e: Event): void {
    const rateButton = (e as MouseEvent).target as any;
    if (rateButton.initialRateValue === gUserRating) return;

    for (let i = 0; i < gRatingButtons.length; i++) gRatingButtons[i].disabled = true;
    const y = new window.PwgWS((gRatingOptions as any).rootUrl as string);
    y.callService(
        'pwg.images.rate',
        { image_id: (gRatingOptions as any).image_id, rate: rateButton.initialRateValue },
        {
            method: 'POST',
            onFailure: (num: number, text: string) => {
                alert(num + ' ' + text);
                document.location.href = (rateButton.form as HTMLFormElement).action + '&rate=' + rateButton.initialRateValue;
            },
            onSuccess: (result: unknown) => {
                const res = result as any;
                gUserRating = rateButton.initialRateValue;
                for (let i = 0; i < gRatingButtons.length; i++) gRatingButtons[i].disabled = false;
                if ((gRatingOptions as any).onSuccess) (gRatingOptions as any).onSuccess(result);
                if ((gRatingOptions as any).updateRateElement) (gRatingOptions as any).updateRateElement.innerHTML = (gRatingOptions as any).updateRateText;
                if ((gRatingOptions as any).ratingSummaryElement) {
                    let t: string = (gRatingOptions as any).ratingSummaryText;
                    const args = [res.score, res.count, res.average];
                    let idx = 0;
                    const rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
                    (gRatingOptions as any).ratingSummaryElement.innerHTML = t;
                }
            },
        }
    );
}

(function () {
    if (typeof _pwgRatingAutoQueue !== 'undefined' && Array.isArray(_pwgRatingAutoQueue) && _pwgRatingAutoQueue.length) {
        for (let i = 0; i < (_pwgRatingAutoQueue as Array<Record<string, unknown>>).length; i++) {
            makeNiceRatingForm((_pwgRatingAutoQueue as Array<Record<string, unknown>>)[i]);
        }
    }
    _pwgRatingAutoQueue = {
        push: (opts: Record<string, unknown>) => { makeNiceRatingForm(opts); },
    };
})();
