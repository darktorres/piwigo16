var gRatingOptions;
var gRatingButtons;
var gUserRating;

function makeNiceRatingForm(options) {
    gRatingOptions = options;
    var form = document.getElementById("rateForm");
    if (!form) return; //? template changed

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
            var targetValue = e.target
                ? e.target.dataset.initialRateValue
                : e.srcElement.dataset.initialRateValue;
            updateRatingStarDisplay(targetValue);
        });
    });

    updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating) {
    gRatingButtons.forEach(function (button) {
        var initialValue = parseFloat(button.dataset.initialRateValue);
        var shouldBeFull = userRating !== "" && userRating >= initialValue;

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
    var elem = e.target || e.srcElement;
    var rateButtonValue = elem.dataset.initialRateValue;
    var isDisabled = elem.dataset.disabled == "true";

    if (isDisabled || rateButtonValue == gUserRating) {
        return false; //nothing to do
    }

    gRatingButtons.forEach(function (btn) {
        elem.dataset.disabled = "true";
    });

    var y = new PwgWS(gRatingOptions.rootUrl);
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
                var rateForm = document.getElementById("rateForm");
                var action = rateForm ? rateForm.getAttribute("action") : "";
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
                    var t = gRatingOptions.ratingSummaryText;
                    var args = [result.score, result.count, result.average];
                    var idx = 0;
                    var rexp = new RegExp(/%\.?\d*[sdf]/);
                    while (idx < args.length) t = t.replace(rexp, args[idx++]);
                    gRatingOptions.ratingSummaryElement.innerHTML = t;
                }
            },
        },
    );
    return false;
}

(function () {
    if (
        typeof _pwgRatingAutoQueue != "undefined" &&
        _pwgRatingAutoQueue.length
    ) {
        for (var i = 0; i < _pwgRatingAutoQueue.length; i++)
            makeNiceRatingForm(_pwgRatingAutoQueue[i]);
    }
    _pwgRatingAutoQueue = {
        push: function (opts) {
            makeNiceRatingForm(opts);
        },
    };
})();
