export {};

jQuery(document).ready(function () {
  jQuery("#checkAllLink").click(function () {
    jQuery("#c13y input[type=checkbox]").prop("checked", true);
    return false;
  });

  jQuery("#uncheckAllLink").click(function () {
    jQuery("#c13y input[type=checkbox]").prop("checked", false);
    return false;
  });

  jQuery("#checkAutomaticCorrectionsLink").click(function () {
    DeselectAll(document.getElementById("c13y"));
    const ids = pwg_getPageData("c13y_do_check_ids") || [];
    ids.forEach(function (id: any) {
      (
        document.getElementById("c13y_selection-" + id) as HTMLInputElement
      ).checked = true;
    });
    return false;
  });
});

function DeselectAll(formulaire: any) {
  const elts = formulaire.elements;
  for (let i = 0; i < elts.length; i++) {
    if (elts[i].type === "checkbox") elts[i].checked = false;
  }
}
