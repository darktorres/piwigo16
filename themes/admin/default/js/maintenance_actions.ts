export {};

const confirm_msg = pwg_getPageString('Yes, I am sure');
const cancel_msg = pwg_getPageString('No, I have changed my mind');
const selected: any[] = [];
$(".lock-gallery-button").each(function() {
  const gallery_tip = pwg_getPageString('A locked gallery is only visible to administrators');
  const title = pwg_getPageData('u_maint_lock_gallery') ? pwg_getPageString('Are you sure you want to lock the gallery?') : pwg_getPageString('Are you sure you want to unlock the gallery?');

  $(this).pwg_jconfirm_follow_href({
    alert_title: title,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
    alert_content: gallery_tip
  });
});
$(".purge-history-detail-button").each(function() {
  const title = pwg_getPageString('Purge history detail');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
$(".purge-history-summary-button").each(function() {
  const title = pwg_getPageString('Purge history summary');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
$(".purge-search-history-button").each(function() {
  const title = pwg_getPageString('Purge search history');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
$(".delete-all-sizes-button").each(function() {
  const title = pwg_getPageString('Are you sure you want to delete all sizes?');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});

$(".delete-size-check").click(function () {
  if ($(this).attr('data-selected') === '1') {
    $(this).attr('data-selected', '0');
    $(this).find("i").hide();
  } else {
    $(this).attr('data-selected', '1');
    $(this).find("i").show();
  }
  $(this).trigger("change");
});
$(".delete-size-check:first").change(function() {
  if ($(this).attr('data-selected') === '1') {
    $(".delete-size-check").hide();
    $(".delete-size-check").attr("data-selected", "1");
    $(this).show();
  } else {
    $(".delete-size-check").show();
    $(".delete-size-check").attr("data-selected", "0");
  }
})
const delete_deriv_URL = "admin.php?page=maintenance&action=derivatives&";
$(".delete-size-check").change(function() {
  const delete_deriv_with_token = delete_deriv_URL + "pwg_token=" + pwg_getPageData('pwg_token') + "&";
  let types_str;
  const selected: any[] = []
  $(".delete-size-check").each(function () {
    if ($(this).attr("data-selected") === '1') {
      selected.push($(this).attr("name"));
    }
  })
  if (selected.length === 0) {
    $(".delete-sizes").attr("href", "");
  } else {
    if (selected[0] === "all") {
      types_str = "all";
    } else {
      types_str = selected.join("_");
    }
    console.log(selected);
    $(".delete-sizes").attr("href", delete_deriv_with_token + "type=" + types_str);
  }
})

$(".delete-sizes").hide();
$(".delete-size-check").click( function () {
  let displayDeleteSizes = false;
  $(".delete-size-check").each(function() {
    if ($(this).attr("data-selected") === '1') {
      displayDeleteSizes = true;
    }
  });

  if (displayDeleteSizes) {
    $(".delete-sizes").show();
  } else {
    $(".delete-sizes").hide();
  }

})
