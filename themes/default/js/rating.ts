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
        try {
            rateButton.type = 'button';
        } catch (e) {}

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
        pwgAddEventListener(rateButton, 'mouseout', () => {
            updateRatingStarDisplay(gUserRating);
        });
        pwgAddEventListener(rateButton, 'mouseover', (e: Event) => {
            const target = (e as MouseEvent).target as any;
            updateRatingStarDisplay(target.initialRateValue);
        });
    }
    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string): void {
    for (let i = 0; i < gRatingButtons.length; i++) {
        gRatingButtons[i].className =
            userRating !== '' && userRating >= (gRatingButtons[i] as any).initialRateValue
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
                document.location.href =
                    (rateButton.form as HTMLFormElement).action +
                    '&rate=' +
                    rateButton.initialRateValue;
            },
            onSuccess: (result: unknown) => {
                const res = result as any;
                gUserRating = rateButton.initialRateValue;
                for (let i = 0; i < gRatingButtons.length; i++) gRatingButtons[i].disabled = false;
                if ((gRatingOptions as any).onSuccess) (gRatingOptions as any).onSuccess(result);
                if ((gRatingOptions as any).updateRateElement)
                    (gRatingOptions as any).updateRateElement.innerHTML = (
                        gRatingOptions as any
                    ).updateRateText;
                if ((gRatingOptions as any).ratingSummaryElement) {
                    let t: string = (gRatingOptions as any).ratingSummaryText;
                    const args = [res.score, res.count, res.average];
                    let idx = 0;
                    const rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
                    (gRatingOptions as any).ratingSummaryElement.innerHTML = t;
                }
                // i18n strings passed directly as data (no callback)
                const opts = gRatingOptions as any;
                if (opts.str_update_your_rating) {
                    const e = document.getElementById('updateRate');
                    if (e) e.innerHTML = opts.str_update_your_rating;
                }
                if (opts.str_rate || opts.str_rates) {
                    const e = document.getElementById('ratingCount');
                    if (e) {
                        const tpl =
                            res.count === 1 ? (opts.str_rate ?? '') : (opts.str_rates ?? '');
                        e.innerHTML = '(' + tpl.replace('%d', String(res.count)) + ')';
                    }
                }
                if (opts.str_rate !== undefined) {
                    const e = document.getElementById('ratingScore');
                    if (e) e.innerHTML = res.score;
                }
            },
        }
    );
}

// Process any legacy _pwgRatingAutoQueue queue (plugins may still push to it)
// then auto-discover the rating form on the current page via its data-* attrs.
if (
    typeof _pwgRatingAutoQueue !== 'undefined' &&
    Array.isArray(_pwgRatingAutoQueue) &&
    _pwgRatingAutoQueue.length
) {
    for (let i = 0; i < (_pwgRatingAutoQueue as Array<Record<string, unknown>>).length; i++) {
        makeNiceRatingForm((_pwgRatingAutoQueue as Array<Record<string, unknown>>)[i]);
    }
}
_pwgRatingAutoQueue = {
    push: (opts: Record<string, unknown>) => {
        makeNiceRatingForm(opts);
    },
};

const ratingForm = document.getElementById('rateForm');
if (ratingForm?.dataset['rootUrl']) {
    makeNiceRatingForm({
        rootUrl: ratingForm.dataset['rootUrl'],
        image_id: Number(ratingForm.dataset['imageId']),
        str_update_your_rating: ratingForm.dataset['strUpdateYourRating'],
        str_rate: ratingForm.dataset['strRate'],
        str_rates: ratingForm.dataset['strRates'],
    });
}

export {};
