import { PwgWS, pwgAddEventListener } from './scripts.js';

let gRatingOptions;
let gRatingButtons;
let gUserRating;

export function makeNiceRatingForm(options) {
    gRatingOptions = options;
    const form = document.getElementById("rateForm");
    if (!form) return;

    gRatingButtons = form.getElementsByTagName("input");
    gUserRating = "";
    for (let i = 0; i < gRatingButtons.length; i++) {
        if (gRatingButtons[i].type == "button") {
            gUserRating = gRatingButtons[i].value;
            break;
        }
    }

    for (let i = 0; i < gRatingButtons.length; i++) {
        const rateButton = gRatingButtons[i];
        rateButton.initialRateValue = rateButton.value;
        try {
            rateButton.type = "button";
        } catch (_e) {}

        rateButton.value = " ";
        rateButton.style.marginLeft = 0;
        rateButton.style.marginRight = 0;

        if (
            i != gRatingButtons.length - 1 &&
            rateButton.nextSibling.nodeType == 3
        )
            rateButton.parentNode.removeChild(rateButton.nextSibling);
        if (i > 0 && rateButton.previousSibling.nodeType == 3)
            rateButton.parentNode.removeChild(rateButton.previousSibling);

        pwgAddEventListener(rateButton, "click", updateRating);
        pwgAddEventListener(rateButton, "mouseout", function () {
            updateRatingStarDisplay(gUserRating);
        });
        pwgAddEventListener(rateButton, "mouseover", function (e) {
            updateRatingStarDisplay(
                e.target
                    ? e.target.initialRateValue
                    : e.srcElement.initialRateValue,
            );
        });
    }
    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating) {
    for (let i = 0; i < gRatingButtons.length; i++)
        gRatingButtons[i].className =
            userRating !== "" &&
            userRating >= gRatingButtons[i].initialRateValue
                ? "rateButtonStarFull"
                : "rateButtonStarEmpty";
}

function updateRating(e) {
    const rateButton = e.target || e.srcElement;
    if (rateButton.initialRateValue == gUserRating) return false;

    for (let i = 0; i < gRatingButtons.length; i++)
        gRatingButtons[i].disabled = true;
    const y = new PwgWS(gRatingOptions.rootUrl);
    y.callService(
        "pwg.images.rate",
        {
            image_id: gRatingOptions.image_id,
            rate: rateButton.initialRateValue,
        },
        {
            method: "POST",
            onFailure: function (num, text) {
                alert(num + " " + text);
                document.location =
                    rateButton.form.action +
                    "&rate=" +
                    rateButton.initialRateValue;
            },
            onSuccess: function (result) {
                gUserRating = rateButton.initialRateValue;
                for (let i = 0; i < gRatingButtons.length; i++)
                    gRatingButtons[i].disabled = false;
                if (gRatingOptions.onSuccess) gRatingOptions.onSuccess(result);
                if (gRatingOptions.updateRateElement)
                    gRatingOptions.updateRateElement.innerHTML =
                        gRatingOptions.updateRateText;
                if (gRatingOptions.ratingSummaryElement) {
                    let t = gRatingOptions.ratingSummaryText;
                    const args = [result.score, result.count, result.average];
                    let idx = 0;
                    const rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, args[idx++]);
                    gRatingOptions.ratingSummaryElement.innerHTML = t;
                }
            },
        },
    );
    return false;
}

export function initRating() {
    if (document._pwgRatingQueue && Array.isArray(document._pwgRatingQueue)) {
        for (let i = 0; i < document._pwgRatingQueue.length; i++)
            makeNiceRatingForm(document._pwgRatingQueue[i]);
    }
    window._pwgRatingAutoQueue = {
        push: function (opts) {
            makeNiceRatingForm(opts);
        },
    };
}

document.addEventListener('DOMContentLoaded', initRating);
