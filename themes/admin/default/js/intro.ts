const piwigo_need_update_msg = '<a href="admin.php?page=updates">' + pwg_getPageString('A new version of Piwigo is available.') + ' <i class="icon-right"></i></a>';
const ext_need_update_msg = '<a href="admin.php?page=updates&amp;tab=ext">' + pwg_getPageString('Some upgrades are available for extensions.') + ' <i class="icon-right"></i></a>';
const str_gb_used = pwg_getPageString('%s GB used');
const str_mb_used = pwg_getPageString('%s MB used');
const str_gb = pwg_getPageString('%sGB').replace(' ', '&nbsp;');
const str_mb = pwg_getPageString('%sMB').replace(' ', '&nbsp;');
const storage_total = pwg_getPageData('storage_total');
const storage_details: Record<string, any> = pwg_getPageData('storage_chart_data');
const translate_files = pwg_getPageString('%d files');
const newsletter_base_url = pwg_getPageData('subscribe_base_url');

const translate_type: Record<string, string> = {};
Object.keys(storage_details).forEach(function(type) {
  translate_type[type] = pwg_getPageString(type);
});

jQuery().ready(function(){
  jQuery('.cluetip').cluetip({
    width: 300,
    splitTitle: '|',
    positionBy: 'bottomTop'
  });

  if (pwg_getPageData('check_for_updates')) {
    jQuery.ajax({
      type: 'GET',
      url: 'api/v1/extensions/updates',
      dataType: 'json',
      timeout: 5000,
      success: function (data: any) {
        const piwigo_update = data['piwigoNeedUpdate'];
        const ext_update = data['extNeedUpdate']
        if ((piwigo_update || ext_update) && !jQuery(".warnings").is('div'))
          jQuery(".eiw").prepend('<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>');
        if (piwigo_update)
          jQuery(".warnings ul").append('<li>'+piwigo_need_update_msg+'</li>');
        if (ext_update)
          jQuery(".warnings ul").append('<li>'+ext_need_update_msg+'</li>');
      }
    });
  }

  if (pwg_getPageData('subscribe_base_url')) {
    jQuery(".eiw").prepend(`
    <div class="promote-newsletter">
      <div class="promote-content">

        <img class="promote-image" src="themes/admin/default/images/promote-newsletter.png">

        <div class="promote-newsletter-content">
          <span class="promote-newsletter-title">${pwg_getPageString('Subscribe to our newsletter and stay updated!')}</span>
          <div class="promote-content subscribe-newsletter">
            <input type="text" id="newsletterSubscribeInput" value="${pwg_getPageData('email') || ''}" class="left-side">
            <a href="${pwg_getPageData('subscribe_base_url')}${pwg_getPageData('email') || ''}" id="newsletterSubscribeLink" class="right-side go-to-porg icon-thumbs-up newsletter-hide">${pwg_getPageString('Sign up to the newsletter')}</a>
          </div>
          <a href="${pwg_getPageData('old_newsletters_url') || ''}" class="promote-link">${pwg_getPageString('See previous newsletters')}</a>
        </div>

      </div>
      <a href="#" class="dont-show-again icon-cancel tiptip newsletter-hide" title="${pwg_getPageString('Understood, do not show again')}"></a>
    </div>`);

  }

  jQuery("#newsletterSubscribeInput").change(function(){
    jQuery("#newsletterSubscribeLink").attr("href", newsletter_base_url + String(jQuery("#newsletterSubscribeInput").val()))
  })

  jQuery('.newsletter-hide').click(function() {
    jQuery('.promote-newsletter').hide();

    jQuery.ajax({
      type: 'GET',
      url: 'admin.php?action=hide_newsletter_subscription'
    });

    if (jQuery(this).hasClass('newsletter-hide')) {
      return false;
    }
  });
  const size_info = storage_total > 1000000 ? str_gb_used : str_mb_used;
  const size_nb = storage_total > 1000000 ? (storage_total / 1000000).toFixed(2) : (storage_total / 1000).toFixed(0);
  $(".chart-title-infos").html(size_info.replace("%s", size_nb));
});

// Explicit `window.` exposure -- required, not decorative (see
// page-data.ts's own copy of this comment, docs/PLAN.md P46-B, for the
// full explanation). `intro_tooltips.ts` reads all 5 bare.
window.str_gb = str_gb;
window.str_mb = str_mb;
window.storage_details = storage_details;
window.translate_files = translate_files;
window.translate_type = translate_type;
