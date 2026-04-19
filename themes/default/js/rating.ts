import { PwgWS, pwgAddEventListener } from './scripts.ts';

interface RateButton extends HTMLInputElement {
    initialRateValue: string;
}

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
let gRatingButtons: HTMLCollectionOf<RateButton>;
let gUserRating: string;

export function makeNiceRatingForm(options: RatingOptions): void {
    gRatingOptions = options;
    const form = document.getElementById("rateForm");
    if (!form) return;

    gRatingButtons = form.getElementsByTagName("input") as HTMLCollectionOf<RateButton>;
    gUserRating = "";
    for (let i = 0; i < gRatingButtons.length; i++) {
        if (gRatingButtons[i]!.type == "button") {
            gUserRating = gRatingButtons[i]!.value;
            break;
        }
    }

    for (let i = 0; i < gRatingButtons.length; i++) {
        const rateButton = gRatingButtons[i]!;
        rateButton.initialRateValue = rateButton.value;
        try {
            rateButton.type = "button";
        } catch (_e) { /* read-only in some browsers */ }

        rateButton.value = " ";
        rateButton.style.marginLeft = "0";
        rateButton.style.marginRight = "0";

        if (
            i != gRatingButtons.length - 1 &&
            rateButton.nextSibling !== null &&
            rateButton.nextSibling.nodeType == 3
        )
            rateButton.parentNode?.removeChild(rateButton.nextSibling);
        if (i > 0 && rateButton.previousSibling !== null && rateButton.previousSibling.nodeType == 3)
            rateButton.parentNode?.removeChild(rateButton.previousSibling);

        pwgAddEventListener(rateButton, "click", updateRating);
        pwgAddEventListener(rateButton, "mouseout", function () {
            updateRatingStarDisplay(gUserRating);
        });
        pwgAddEventListener(rateButton, "mouseover", function (e: Event) {
            const target = (e.target || (e as unknown as { srcElement?: RateButton }).srcElement) as RateButton | null;
            updateRatingStarDisplay(
                target ? target.initialRateValue : ''
            );
        });
    }
    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string): void {
    for (let i = 0; i < gRatingButtons.length; i++)
        gRatingButtons[i]!.className =
            userRating !== "" &&
            userRating >= gRatingButtons[i]!.initialRateValue
                ? "rateButtonStarFull"
                : "rateButtonStarEmpty";
}

function updateRating(e: Event): boolean {
    const rateButton = (e.target || (e as unknown as { srcElement?: RateButton }).srcElement) as RateButton;
    if (rateButton.initialRateValue == gUserRating) return false;

    for (let i = 0; i < gRatingButtons.length; i++)
        gRatingButtons[i]!.disabled = true;
    const y = new PwgWS(gRatingOptions.rootUrl);
    y.callService(
        "pwg.images.rate",
        {
            image_id: gRatingOptions.image_id,
            rate: rateButton.initialRateValue,
        },
        {
            method: "POST",
            onFailure: function (num: number, text: string) {
                alert(num + " " + text);
                document.location =
                    (rateButton.form ? rateButton.form.action : '') +
                    "&rate=" +
                    rateButton.initialRateValue;
            },
            onSuccess: function (rawResult: unknown) {
                const result = rawResult as RatingResult;
                gUserRating = rateButton.initialRateValue;
                for (let i = 0; i < gRatingButtons.length; i++)
                    gRatingButtons[i]!.disabled = false;
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
