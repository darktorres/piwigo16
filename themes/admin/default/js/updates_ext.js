var pwg_token = pwg_getPageData('csrf_token');
var extType = pwg_getPageData('ext_type');
var errorHead   = pwg_getPageString('ERROR');
var successHead = pwg_getPageString('Update Complete');
var errorMsg    = pwg_getPageString('an error happened');
var restoreMsg  = pwg_getPageString('Reset ignored updates');

var todo = 0;
var queuedManager = $.manageAjax.create('queued', {
  queue: true,
  maxRequests: 1,
  beforeSend: function() { autoupdate_bar_toggle(1); },
  complete: function() { autoupdate_bar_toggle(-1); }
});

function updateAll() {
  jQuery('.updateExtension').each( function() {
    if (jQuery(this).parents('div').css('display') === 'block')
      jQuery(this).click();
  });
};

function ignoreAll() {
  jQuery('.ignoreExtension').each( function() {
    if (jQuery(this).parents('div').css('display') === 'block')
      jQuery(this).click();
  });
};

function resetIgnored() {
  jQuery.ajax({
    type: 'POST',
    url: 'api/v1/extensions/updates/ignore',
    contentType: 'application/json',
    headers: {'X-CSRF-Token': pwg_token},
    dataType: 'json',
    data: JSON.stringify({ reset: true, type: extType }),
    success: function(data) {
      jQuery(".pluginBox, fieldset").show();
      jQuery(".pluginBox").attr('data-ignored', 'false')
      jQuery("#update_all").show();
      jQuery("#ignore_all").show();
      jQuery("#up_to_date").hide();
      jQuery("#reset_ignore").hide();
      jQuery("#ignored").hide();
      checkFieldsets();
    }
  });
};

function checkFieldsets() {
  var types = new Array('plugins', 'themes', 'languages');
  var total = 0;
  var ignored = 0;
  var nbExtensions;
  for (var i=0;i<3;i++) {
    nbExtensions = 0;
    jQuery("fieldset[data-type="+types[i]+"] .pluginBox").each(function(index) {
      if (jQuery(this).attr('data-ignored') === 'true')
        ignored++;
      else
        nbExtensions++;
    });
    total = total + nbExtensions;
    if (nbExtensions === 0)
      jQuery("#"+types[i]).hide();
  }

  if (total === 0) {
    jQuery("#update_all").hide();
    jQuery("#ignore_all").hide();
    jQuery("#up_to_date").show();
  }
  if (ignored > 0) {
    jQuery("#reset_ignore").val(restoreMsg + ' (' + ignored + ')');
  }
};

function updateExtension(type, id, revision) {
  queuedManager.add({
    type: 'POST',
    dataType: 'json',
    contentType: 'application/json',
    headers: {'X-CSRF-Token': pwg_token},
    url: 'api/v1/extensions/' + type + '/' + id + '/actions/update',
    data: JSON.stringify({ revision: revision }),
    success: function(data) {
      jQuery.jGrowl( data['message'], { theme: 'success', header: successHead, life: 4000, sticky: false });
      jQuery("#"+type+"_"+id).remove();
      checkFieldsets();
    },
    error: function(jqXHR) {
      var message = jqXHR.responseJSON && jqXHR.responseJSON.detail ? jqXHR.responseJSON.detail : errorMsg;
      jQuery.jGrowl( message, { theme: 'error', header: errorHead, sticky: true });
    }
  });
};

var targetNode = document.getElementById("theAdminPage");

var config = { attributes: false, childList: true, subtree: true };

var callback = (mutationList, observer) => {
  for (const mutation of mutationList) {
    if (mutation.type === "childList") {
      var popup = jQuery("#jGrowl").children();
      for (var i = 0; i < popup.length; i++){
        if ((jQuery(popup[i])).hasClass("success")){
          if (! ((jQuery(popup[i]).children(":first")).hasClass("jGrowl-popup-icon icon-ok"))){
            jQuery(popup[i]).prepend('<div class="jGrowl-popup-icon icon-ok"></div>')
          }
        };

        if ((jQuery(popup[i])).hasClass("error")){
          if (! ((jQuery(popup[i]).children(":first")).hasClass("jGrowl-popup-icon icon-cancel"))){
            jQuery(popup[i]).prepend('<div class="jGrowl-popup-icon icon-cancel"></div>')
          }
        }
      };
    }
  }
};

var observer = new MutationObserver(callback);
observer.observe(targetNode, config);

function ignoreExtension(type, id) {
  queuedManager.add({
    type: 'POST',
    url: 'api/v1/extensions/updates/ignore',
    contentType: 'application/json',
    headers: {'X-CSRF-Token': pwg_token},
    dataType: 'json',
    data: JSON.stringify({ type: type, id: id }),
    success: function(data) {
      jQuery("#"+type+"_"+id).hide();
      jQuery("#"+type+"_"+id).attr('data-ignored', 'true')
      jQuery("#reset_ignore").show();
      checkFieldsets();
    }
  });
};

function autoupdate_bar_toggle(i) {
  todo = todo + i;
  if ((i === 1 && todo === 1) || (i === -1 && todo === 0))
    jQuery('.autoupdate_bar').toggle();
}

checkFieldsets();

var confirm_msg = pwg_getPageString('Yes, I am sure');
var cancel_msg = pwg_getPageString('No, I have changed my mind');
$("#update_all").click(function() {
  var title_msg = pwg_getPageString('Are you sure you want to update all extensions?');
  $.confirm({
      title: title_msg,
      buttons: {
        confirm: {
          text: confirm_msg,
          btnClass: 'btn-red',
          action: function () {
            updateAll();
          }
        },
        cancel: {
          text: cancel_msg
        }
      },
      ...jConfirm_confirm_options
    });
})
