export {};

function from_coi(f: number, total: number) {
  return f * total;
}

function to_coi(v: number, total: number) {
  return v / total;
}

function jOnChange(sel: any) {
  const $img = jQuery("#jcrop");
  jQuery("#l").val(to_coi(sel.x, $img.width()!));
  jQuery("#t").val(to_coi(sel.y, $img.height()!));
  jQuery("#r").val(to_coi(sel.x2, $img.width()!));
  jQuery("#b").val(to_coi(sel.y2, $img.height()!));
}
function jOnRelease() {
  jQuery("#l,#t,#r,#b").val("");
}

const coi = pwg_getPageData("coi");

jQuery("#jcrop").Jcrop(
  {
    boxWidth: 500,
    boxHeight: 400,
    onChange: jOnChange,
    onRelease: jOnRelease,
  },
  coi
    ? function (this: any) {
        const $img = jQuery("#jcrop");
        this.animateTo([
          from_coi(coi.l, $img.width()!),
          from_coi(coi.t, $img.height()!),
          from_coi(coi.r, $img.width()!),
          from_coi(coi.b, $img.height()!),
        ]);
      }
    : undefined,
);
