import { ajax, AjaxError } from "./vendor/utils/ajax";

// Real consumer of scripts.ts's own top-level `pwgAddEventListener`
// (docs/PLAN.md P48 -- was a bare ambient-global read, see that file's
// own leading comment for the full real-consumer list, and Design §6
// for the real Async-depends-on-Async race this file was already the
// documented example of). This file itself becomes a real module as a
// result (previously non-module) -- `drainRatingAutoQueue()` below is
// a separate, already-established queue-based deferred-init pattern
// (P47's RatingAutoQueue redesign, moved off the ambient
// `window._pwgRatingAutoQueue` global onto a real shared module at
// P51-H), not a plain function exposure, and stays exactly as it is.
// This file's own standalone Vite entry/registration also stays
// exactly as it is (docs/PLAN.md P48, its own catalog line's
// investigation, alongside switchbox.ts's own real fold): it has
// exactly one real registrant page (PictureView) but that registration
// is *conditional* ($rating may be null), so folding it into
// picture.ts's own always-present bundle would make it unconditionally
// present instead -- a real behavior change, not just a request-count
// optimization, so it's excluded here.
import { pwgAddEventListener } from "./scripts";
import {
  drainRatingAutoQueue,
  type PwgRatingOptions,
  type PwgRatingResult,
} from "./ratingAutoQueue";

interface RatingButton extends HTMLInputElement {
  initialRateValue: string;
}

let gRatingOptions: PwgRatingOptions;
let gRatingButtons: HTMLCollectionOf<RatingButton>;
let gUserRating: string;

function makeNiceRatingForm(options: PwgRatingOptions) {
  gRatingOptions = options;
  const form = document.getElementById("rateForm");
  if (!form) return; //? template changed

  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- RatingButton's own extra `initialRateValue` property is added to each of these real <input> elements a few lines below, a self-authored runtime extension.
  gRatingButtons = form.getElementsByTagName(
    "input",
  ) as HTMLCollectionOf<RatingButton>;
  gUserRating = "";
  for (const button of gRatingButtons) {
    if (button.type === "button") {
      gUserRating = button.value;
      break;
    }
  }

  const buttonCount = gRatingButtons.length;
  for (const [i, rateButton] of Array.from(gRatingButtons).entries()) {
    rateButton.initialRateValue = rateButton.value; // save it as a property
    try {
      rateButton.type = "button";
    } catch {
      /* avoid normal submit (use ajax); not working in IE6 */
    }

    rateButton.value = " "; //hide the text (Apple + IE would show text above the stars)
    rateButton.style.marginLeft = rateButton.style.marginRight = "0";

    if (
      i !== buttonCount - 1 &&
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- guarded by the != last-button check: a non-last rateButton always has a real nextSibling here (the whitespace text node between <input>s).
      rateButton.nextSibling!.nodeType === 3 /*TEXT_NODE*/
    )
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every rateButton is always a real child of #rateForm's own DOM, and the sibling check above just confirmed nextSibling is real.
      rateButton.parentNode!.removeChild(rateButton.nextSibling!);
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- guarded by the i > 0 check: a non-first rateButton always has a real previousSibling here (the whitespace text node between <input>s).
    if (i > 0 && rateButton.previousSibling!.nodeType === 3 /*TEXT_NODE*/)
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every rateButton is always a real child of #rateForm's own DOM, and the sibling check above just confirmed previousSibling is real.
      rateButton.parentNode!.removeChild(rateButton.previousSibling!);

    pwgAddEventListener(rateButton, "click", updateRating);
    pwgAddEventListener(rateButton, "mouseout", handleRatingMouseout);
    pwgAddEventListener(rateButton, "mouseover", handleRatingMouseover);
  }
  updateRatingStarDisplay(gUserRating);
}

function updateRatingStarDisplay(userRating: string) {
  for (const button of gRatingButtons)
    button.className =
      userRating !== "" && userRating >= button.initialRateValue
        ? "rateButtonStarFull"
        : "rateButtonStarEmpty";
}

function handleRatingMouseout(): void {
  updateRatingStarDisplay(gUserRating);
}

// Explicit `e: Event` needed (docs/PLAN.md P48) -- TS's own contextual
// parameter typing doesn't flow through a union-typed parameter
// (`EventListenerOrEventListenerObject`) the same way through a real
// `import`ed function as it did through the old ambient `declare
// function`: this callback's own `e` silently typed as `Event` before,
// `any` (a real `noImplicitAny` error) after.
function handleRatingMouseover(e: Event): void {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- pwgAddEventListener() only ever binds this handler to a real gRatingButtons entry, always a real RatingButton at runtime.
  const target = e.target as RatingButton;
  updateRatingStarDisplay(target.initialRateValue);
}

function updateRating(e: Event): void {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- pwgAddEventListener() only ever binds this handler to a real gRatingButtons entry, always a real RatingButton at runtime.
  const rateButton = e.target as RatingButton;
  if (rateButton.initialRateValue === gUserRating) return; //nothing to do

  for (const button of gRatingButtons) button.disabled = true;

  void (async () => {
    try {
      const result = await ajax<PwgRatingResult>({
        url:
          gRatingOptions.rootUrl +
          "api/v1/images/" +
          String(gRatingOptions.image_id) +
          "/rating",
        method: "PUT",
        json: { rate: rateButton.initialRateValue },
      });

      gUserRating = rateButton.initialRateValue;
      for (const button of gRatingButtons) button.disabled = false;
      if (gRatingOptions.onSuccess) gRatingOptions.onSuccess(result);
      if (gRatingOptions.updateRateElement)
        gRatingOptions.updateRateElement.innerHTML =
          // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- updateRateElement/updateRateText are always set together by this option's own real caller.
          gRatingOptions.updateRateText!;
      if (gRatingOptions.ratingSummaryElement) {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- ratingSummaryElement/ratingSummaryText are always set together by this option's own real caller.
        let t = gRatingOptions.ratingSummaryText!;
        const args = [result.score, result.count, result.average];
        let idx = 0;
        const rexp = new RegExp(/%\.?\d*[sdf]/);
        while (idx < args.length) t = t.replace(rexp, String(args[idx++]));
        gRatingOptions.ratingSummaryElement.innerHTML = t;
      }
    } catch (e2) {
      alert(
        e2 instanceof AjaxError
          ? String(e2.status) + " " + e2.statusText
          : String(e2),
      );
      document.location.href =
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every rateButton is always a real <input> inside #rateForm.
        rateButton.form!.action + "&rate=" + rateButton.initialRateValue;
    }
  })();
}

drainRatingAutoQueue(makeNiceRatingForm);
