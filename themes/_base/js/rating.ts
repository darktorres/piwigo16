interface RatingOptions {
    rootUrl: string;
    image_id: string | number;
    str_update_your_rating?: string;
    str_rate?: string;
    str_rates?: string;
    onSuccess?: (result: unknown) => void;
    updateRateElement?: HTMLElement;
    updateRateText?: string;
    ratingSummaryElement?: HTMLElement;
    ratingSummaryText?: string;
    [k: string]: unknown;
}

interface RateResult {
    score: number | string;
    count: number;
    average: number | string;
}

interface RateButtonExt extends HTMLInputElement {
    initialRateValue?: string;
}

let gRatingOptions: RatingOptions;
let gRatingButtons: HTMLCollectionOf<HTMLInputElement>;
let gUserRating: string;

function makeNiceRatingForm(options: RatingOptions): void {
    gRatingOptions = options;
    const form = document.getElementById('rateForm');
    if (!form) return;

    gRatingButtons = form.getElementsByTagName('input');
    gUserRating = '';
    for (let i = 0; i < gRatingButtons.length; i++) {
        if (gRatingButtons[i]!.type === 'button') {
            gUserRating = gRatingButtons[i]!.value;
            break;
        }
    }

    for (let i = 0; i < gRatingButtons.length; i++) {
        const rateButton = gRatingButtons[i]! as RateButtonExt;
        rateButton.initialRateValue = rateButton.value;
        try {
            rateButton.type = 'button';
        } catch {
            // Legacy IE throws when changing the type of an already-rendered input;
            // modern browsers tolerate it. Silently ignore for that one edge case.
        }

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
            const target = (e as MouseEvent).target as RateButtonExt;
            updateRatingStarDisplay(target.initialRateValue ?? '');
        });
    }
    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string): void {
    for (let i = 0; i < gRatingButtons.length; i++) {
        const btn = gRatingButtons[i]! as RateButtonExt;
        gRatingButtons[i]!.className =
            userRating !== '' && userRating >= (btn.initialRateValue ?? '')
                ? 'rateButtonStarFull'
                : 'rateButtonStarEmpty';
    }
}

function updateRating(e: Event): void {
    const rateButton = (e as MouseEvent).target as RateButtonExt;
    if (rateButton.initialRateValue === gUserRating) return;

    for (let i = 0; i < gRatingButtons.length; i++) gRatingButtons[i]!.disabled = true;
    const y = new window.PwgWS(gRatingOptions.rootUrl);
    y.callService(
        'pwg.images.rate',
        { image_id: gRatingOptions.image_id, rate: rateButton.initialRateValue ?? '' },
        {
            method: 'POST',
            onFailure: (num: number, text: string) => {
                alert(num + ' ' + text);
                document.location.href =
                    (rateButton.form as HTMLFormElement).action +
                    '&rate=' +
                    (rateButton.initialRateValue ?? '');
            },
            onSuccess: (result: unknown) => {
                const res = result as RateResult;
                gUserRating = rateButton.initialRateValue ?? '';
                for (let i = 0; i < gRatingButtons.length; i++) gRatingButtons[i]!.disabled = false;
                gRatingOptions.onSuccess?.(result);
                if (gRatingOptions.updateRateElement !== undefined)
                    gRatingOptions.updateRateElement.innerHTML =
                        gRatingOptions.updateRateText ?? '';
                if (gRatingOptions.ratingSummaryElement !== undefined) {
                    let t: string = gRatingOptions.ratingSummaryText ?? '';
                    const args: Array<string | number> = [res.score, res.count, res.average];
                    let idx = 0;
                    const rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
                    gRatingOptions.ratingSummaryElement.innerHTML = t;
                }
                // i18n strings passed directly as data (no callback)
                if (
                    gRatingOptions.str_update_your_rating !== undefined &&
                    gRatingOptions.str_update_your_rating !== ''
                ) {
                    const upd = document.getElementById('updateRate');
                    if (upd) upd.innerHTML = gRatingOptions.str_update_your_rating;
                }
                if (
                    gRatingOptions.str_rate !== undefined ||
                    gRatingOptions.str_rates !== undefined
                ) {
                    const cnt = document.getElementById('ratingCount');
                    if (cnt) {
                        const tpl =
                            res.count === 1
                                ? (gRatingOptions.str_rate ?? '')
                                : (gRatingOptions.str_rates ?? '');
                        cnt.innerHTML = '(' + tpl.replace('%d', String(res.count)) + ')';
                    }
                }
                if (gRatingOptions.str_rate !== undefined) {
                    const sc = document.getElementById('ratingScore');
                    if (sc) sc.innerHTML = String(res.score);
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
    for (let i = 0; i < (_pwgRatingAutoQueue as RatingOptions[]).length; i++) {
        makeNiceRatingForm((_pwgRatingAutoQueue as RatingOptions[])[i]!);
    }
}
_pwgRatingAutoQueue = {
    push: (opts: RatingOptions) => {
        makeNiceRatingForm(opts);
    },
};

const ratingForm = document.getElementById('rateForm');
const rootUrl = ratingForm?.dataset['rootUrl'];
if (rootUrl !== undefined && rootUrl !== '') {
    makeNiceRatingForm({
        rootUrl,
        image_id: Number(ratingForm?.dataset['imageId']),
        str_update_your_rating: ratingForm?.dataset['strUpdateYourRating'],
        str_rate: ratingForm?.dataset['strRate'],
        str_rates: ratingForm?.dataset['strRates'],
    });
}

export {};
