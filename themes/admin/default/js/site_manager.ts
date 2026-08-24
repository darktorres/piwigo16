export {};

jQuery(document).ready(function(){
  jQuery("#showCreateSite a").click(function(){
    jQuery("#showCreateSite").hide();
    jQuery("#createSite").show();
  });
});

const title_msg = pwg_getPageString('Are you sure you want to delete this site?');
const confirm_msg = pwg_getPageString('Yes, I am sure');
const cancel_msg = pwg_getPageString('No, I have changed my mind');
$(".delete-site-button").each(function() {
  $(this).pwg_jconfirm_follow_href({
    alert_title: title_msg,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
