import { pwg_getPageData } from "../../../default/js/page-data";
import { height, setVal, width } from "../../../default/js/vendor/dom";
import { jcrop, type JcropApi } from "../../../default/js/vendor/jcrop";
export {};

function from_coi(f: number, total: number) {
  return f * total;
}

function to_coi(v: number, total: number) {
  return v / total;
}

interface JcropSelection {
  x: number;
  y: number;
  x2: number;
  y2: number;
}

/**
 * The crop image, as a real element. width()/height() below are the
 * helper's, which reproduce jQuery's CONTENT-box semantics -- not
 * offsetWidth, which includes padding and border and would shift every
 * stored coordinate.
 */
function cropImage(): HTMLElement | null {
  return document.querySelector<HTMLElement>("#jcrop");
}

function jOnChange(sel: JcropSelection) {
  const img = cropImage();
  if (img === null) {
    return;
  }

  // setVal takes a string; jQuery coerced the number itself.
  setVal(document.querySelectorAll("#l"), String(to_coi(sel.x, width(img))));
  setVal(document.querySelectorAll("#t"), String(to_coi(sel.y, height(img))));
  setVal(document.querySelectorAll("#r"), String(to_coi(sel.x2, width(img))));
  setVal(document.querySelectorAll("#b"), String(to_coi(sel.y2, height(img))));
}
function jOnRelease() {
  setVal(document.querySelectorAll("#l,#t,#r,#b"), "");
}

const coi = pwg_getPageData<
  { l: number; t: number; r: number; b: number } | undefined
>("coi");

jcrop(
  document.querySelector<HTMLImageElement>("#jcrop")!,
  {
    boxWidth: 500,
    boxHeight: 400,
    onChange: jOnChange,
    onRelease: jOnRelease,
  },
  coi
    ? function (this: JcropApi) {
        const img = cropImage();
        if (img === null) {
          return;
        }

        this.animateTo([
          from_coi(coi.l, width(img)),
          from_coi(coi.t, height(img)),
          from_coi(coi.r, width(img)),
          from_coi(coi.b, height(img)),
        ]);
      }
    : undefined,
);
