interface RatingButton extends HTMLInputElement {
  initialRateValue: string;
}

interface PwgRatingResult {
  score: number;
  count: number;
  average?: number;
}

interface PwgRatingOptions {
  rootUrl: string;
  image_id: string | number;
  onSuccess?: (result: PwgRatingResult) => void;
  updateRateElement?: HTMLElement;
  updateRateText?: string;
  ratingSummaryElement?: HTMLElement;
  ratingSummaryText?: string;
}

let gRatingOptions: PwgRatingOptions;
let gRatingButtons: HTMLCollectionOf<RatingButton>;
let gUserRating: string;

function makeNiceRatingForm(options: PwgRatingOptions) {
  gRatingOptions = options;
  const form = document.getElementById("rateForm");
  if (!form) return; //? template changed

  gRatingButtons = form.getElementsByTagName(
    "input",
  ) as HTMLCollectionOf<RatingButton>;
  gUserRating = "";
  for (let i = 0; i < gRatingButtons.length; i++) {
    if (gRatingButtons[i]!.type == "button") {
      gUserRating = gRatingButtons[i]!.value;
      break;
    }
  }

  for (let i = 0; i < gRatingButtons.length; i++) {
    const rateButton = gRatingButtons[i]!;
    rateButton.initialRateValue = rateButton.value; // save it as a property
    try {
      rateButton.type = "button";
    } catch {
      /* avoid normal submit (use ajax); not working in IE6 */
    }

    rateButton.value = " "; //hide the text (Apple + IE would show text above the stars)
    rateButton.style.marginLeft = rateButton.style.marginRight = "0";

    if (
      i != gRatingButtons.length - 1 &&
      rateButton.nextSibling!.nodeType == 3 /*TEXT_NODE*/
    )
      rateButton.parentNode!.removeChild(rateButton.nextSibling!);
    if (i > 0 && rateButton.previousSibling!.nodeType == 3 /*TEXT_NODE*/)
      rateButton.parentNode!.removeChild(rateButton.previousSibling!);

    pwgAddEventListener(rateButton, "click", updateRating);
    pwgAddEventListener(rateButton, "mouseout", function () {
      updateRatingStarDisplay(gUserRating);
    });
    pwgAddEventListener(rateButton, "mouseover", function (e) {
      const target = (e.target ?? e.srcElement) as RatingButton;
      updateRatingStarDisplay(target.initialRateValue);
    });
  }
  updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string) {
  for (let i = 0; i < gRatingButtons.length; i++)
    gRatingButtons[i]!.className =
      userRating !== "" && userRating >= gRatingButtons[i]!.initialRateValue
        ? "rateButtonStarFull"
        : "rateButtonStarEmpty";
}

function updateRating(e: Event) {
  const rateButton = (e.target || e.srcElement) as RatingButton;
  if (rateButton.initialRateValue == gUserRating) return false; //nothing to do

  for (let i = 0; i < gRatingButtons.length; i++)
    gRatingButtons[i]!.disabled = true;
  $.ajax({
    url:
      gRatingOptions.rootUrl +
      "api/v1/images/" +
      gRatingOptions.image_id +
      "/rating",
    method: "PUT",
    contentType: "application/json",
    data: JSON.stringify({ rate: rateButton.initialRateValue }),
    error: function (jqXHR) {
      alert(jqXHR.status + " " + jqXHR.statusText);
      document.location.href =
        rateButton.form!.action + "&rate=" + rateButton.initialRateValue;
    },
    success: function (result: PwgRatingResult) {
      gUserRating = rateButton.initialRateValue;
      for (let i = 0; i < gRatingButtons.length; i++)
        gRatingButtons[i]!.disabled = false;
      if (gRatingOptions.onSuccess) gRatingOptions.onSuccess(result);
      if (gRatingOptions.updateRateElement)
        gRatingOptions.updateRateElement.innerHTML =
          gRatingOptions.updateRateText!;
      if (gRatingOptions.ratingSummaryElement) {
        let t = gRatingOptions.ratingSummaryText!;
        const args = [result.score, result.count, result.average];
        let idx = 0;
        const rexp = new RegExp(/%\.?\d*[sdf]/);
        while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
        gRatingOptions.ratingSummaryElement.innerHTML = t;
      }
    },
  });
  return false;
}

(function () {
  // `window.` prefix throughout -- see picture.ts's own copy of this
  // comment for why a bare (or `var`-declared) reference to this same
  // global breaks once every P46 entry is wrapped in its own IIFE.
  if (
    typeof window._pwgRatingAutoQueue != "undefined" &&
    window._pwgRatingAutoQueue.length
  ) {
    for (let i = 0; i < window._pwgRatingAutoQueue.length; i++)
      makeNiceRatingForm(window._pwgRatingAutoQueue[i]);
  }
  window._pwgRatingAutoQueue = {
    push: function (opts: PwgRatingOptions) {
      makeNiceRatingForm(opts);
    },
  };
})();
