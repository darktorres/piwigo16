jQuery(document).ready(function(){

  jQuery("#checkAllLink").click(function () {
    jQuery("#c13y input[type=checkbox]").attr('checked', true);
    return false;
  });

  jQuery("#uncheckAllLink").click(function () {
    jQuery("#c13y input[type=checkbox]").attr('checked', false);
    return false;
  });

  jQuery("#checkAutomaticCorrectionsLink").click(function () {
    DeselectAll(document.getElementById('c13y'));
    var ids = pwg_getPageData('c13y_do_check_ids') || [];
    ids.forEach(function(id) {
      document.getElementById('c13y_selection-'+id).checked = true;
    });
    return false;
  });

});

function DeselectAll( formulaire )
{
  var elts = formulaire.elements;
  for(var i=0; i <elts.length; i++)
  {
    if (elts[i].type==='checkbox')
      elts[i].checked = false;
  }
}
