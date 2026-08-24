export {};

jQuery(document).ready(function(){

  jQuery("#checkAllLink").click(function () {
    jQuery("#notification_by_mail input[type=checkbox]").prop('checked', true);
    return false;
  });

  jQuery("#uncheckAllLink").click(function () {
    jQuery("#notification_by_mail input[type=checkbox]").prop('checked', false);
    return false;
  });

});
