import { PwgWS, pwgAddEventListener } from '../../default/js/scripts.ts';

interface RatingOptions {
    rootUrl: string;
    image_id: number | string;
    onSuccess?: (result: RatingResult) => void;
    updateRateElement?: HTMLElement;
    updateRateText?: string;
    ratingSummaryElement?: HTMLElement;
    ratingSummaryText?: string;
}

interface RatingResult {
    score: number | string;
    count: number | string;
    average: number | string;
}

let gRatingOptions: RatingOptions;
let gRatingButtons: NodeListOf<HTMLElement>;
let gUserRating: string;

export function makeNiceRatingForm(options: RatingOptions): void {
    gRatingOptions = options;
    const form = document.getElementById("rateForm");
    if (!form) return;

    gRatingButtons = form.querySelectorAll("span");
    gUserRating = "";
    gRatingButtons.forEach(function (button) {
        if (button.classList.contains("rateButtonStarFull")) {
            gUserRating = button.dataset['value'] ?? '';
        }
    });

    gRatingButtons.forEach(function (button) {
        button.dataset['initialRateValue'] = button.dataset['value'];

        pwgAddEventListener(button, "click", updateRating);
        pwgAddEventListener(button, "mouseout", function () {
            updateRatingStarDisplay(gUserRating);
        });
        pwgAddEventListener(button, "mouseover", function (e: Event) {
            const target = (e.target || (e as unknown as { srcElement?: HTMLElement }).srcElement) as HTMLElement | null;
            const targetValue = target ? target.dataset['initialRateValue'] ?? '' : '';
            updateRatingStarDisplay(targetValue);
        });
    });

    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string): void {
    gRatingButtons.forEach(function (button) {
        const initialValue = parseFloat(button.dataset['initialRateValue'] ?? '');
        const shouldBeFull = userRating !== "" && parseFloat(userRating) >= initialValue;

        if (shouldBeFull) {
            button.classList.add("rateButtonStarFull");
            button.classList.remove("rateButtonStarEmpty");
        } else {
            button.classList.add("rateButtonStarEmpty");
            button.classList.remove("rateButtonStarFull");
        }
    });
}

function updateRating(e: Event): boolean {
    const elem = (e.target || (e as unknown as { srcElement?: HTMLElement }).srcElement) as HTMLElement;
    const rateButtonValue = elem.dataset['initialRateValue'] ?? '';
    const isDisabled = elem.dataset['disabled'] == "true";

    if (isDisabled || rateButtonValue == gUserRating) {
        return false;
    }

    gRatingButtons.forEach(function () {
        elem.dataset['disabled'] = "true";
    });

    const y = new PwgWS(gRatingOptions.rootUrl);
    y.callService(
        "pwg.images.rate",
        {
            image_id: gRatingOptions.image_id,
            rate: rateButtonValue,
        },
        {
            method: "POST",
            onFailure: function (num: number, text: string) {
                alert(num + " " + text);
                const rateForm = document.getElementById("rateForm");
                const action = rateForm ? rateForm.getAttribute("action") : "";
                document.location = (action ?? '') + "&rate=" + rateButtonValue;
            },
            onSuccess: function (rawResult: unknown) {
                const result = rawResult as RatingResult;
                gUserRating = rateButtonValue;
                gRatingButtons.forEach(function () {
                    elem.dataset['disabled'] = "false";
                });
                if (gRatingOptions.onSuccess) gRatingOptions.onSuccess(result);
                if (gRatingOptions.updateRateElement)
                    gRatingOptions.updateRateElement.innerHTML =
                        gRatingOptions.updateRateText ?? '';
                if (gRatingOptions.ratingSummaryElement) {
                    let t = gRatingOptions.ratingSummaryText ?? '';
                    const args: Array<number | string> = [result.score, result.count, result.average];
                    let idx = 0;
                    const rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
                    gRatingOptions.ratingSummaryElement.innerHTML = t;
                }
            },
        },
    );
    return false;
}

export function initRating(): void {
    if (document._pwgRatingQueue && Array.isArray(document._pwgRatingQueue)) {
        for (let i = 0; i < document._pwgRatingQueue.length; i++)
            makeNiceRatingForm(document._pwgRatingQueue[i] as RatingOptions);
    }
    window._pwgRatingAutoQueue = {
        push: function (opts: unknown) {
            makeNiceRatingForm(opts as RatingOptions);
        },
    };
}

document.addEventListener('DOMContentLoaded', initRating);
