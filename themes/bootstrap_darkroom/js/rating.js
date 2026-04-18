import { PwgWS, pwgAddEventListener } from '../default/js/scripts.js';

let gRatingOptions;
let gRatingButtons;
let gUserRating;

export function makeNiceRatingForm(options) {
    gRatingOptions = options;
    const form = document.getElementById("rateForm");
    if (!form) return;

    gRatingButtons = form.querySelectorAll("span");
    gUserRating = "";
    gRatingButtons.forEach(function (button) {
        if (button.classList.contains("rateButtonStarFull")) {
            gUserRating = button.dataset.value;
        }
    });

    gRatingButtons.forEach(function (button) {
        button.dataset.initialRateValue = button.dataset.value;

        pwgAddEventListener(button, "click", updateRating);
        pwgAddEventListener(button, "mouseout", function () {
            updateRatingStarDisplay(gUserRating);
        });
        pwgAddEventListener(button, "mouseover", function (e) {
            const targetValue = e.target
                ? e.target.dataset.initialRateValue
                : e.srcElement.dataset.initialRateValue;
            updateRatingStarDisplay(targetValue);
        });
    });

    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating) {
    gRatingButtons.forEach(function (button) {
        const initialValue = parseFloat(button.dataset.initialRateValue);
        const shouldBeFull = userRating !== "" && userRating >= initialValue;

        if (shouldBeFull) {
            button.classList.add("rateButtonStarFull");
            button.classList.remove("rateButtonStarEmpty");
        } else {
            button.classList.add("rateButtonStarEmpty");
            button.classList.remove("rateButtonStarFull");
        }
    });
}

function updateRating(e) {
    const elem = e.target || e.srcElement;
    const rateButtonValue = elem.dataset.initialRateValue;
    const isDisabled = elem.dataset.disabled == "true";

    if (isDisabled || rateButtonValue == gUserRating) {
        return false;
    }

    gRatingButtons.forEach(function (btn) {
        elem.dataset.disabled = "true";
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
            onFailure: function (num, text) {
                alert(num + " " + text);
                const rateForm = document.getElementById("rateForm");
                const action = rateForm ? rateForm.getAttribute("action") : "";
                document.location = action + "&rate=" + rateButtonValue;
            },
            onSuccess: function (result) {
                gUserRating = rateButtonValue;
                gRatingButtons.forEach(function (btn) {
                    elem.dataset.disabled = "false";
                });
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
