var categoriesCache = new CategoriesCache({
  serverKey: pwg_getPageData('cache_key_categories'),
  serverId: pwg_getPageData('cache_key_hash'),
  rootUrl: pwg_getPageData('root_url')
});

categoriesCache.selectize(jQuery('[data-selectize=categories]'));

jQuery("#removeAlbumFilter").click(function() {
  jQuery("select[name=cat]")[0].selectize.setValue(null);
  return false;
});

function checkCatFilter() {
  if (jQuery("select[name=cat]").val() === "") {
    jQuery("#removeAlbumFilter").hide();
  }
  else {
    jQuery("#removeAlbumFilter").show();
  }
}

checkCatFilter();
jQuery("select[name=cat]").change(function(){
  checkCatFilter();
});

$(document).ready(function() {
  $('h1').append("<span class='badge-number'>" + pwg_getPageData('nb_elements') + "</span>")
});

var pwg_token = pwg_getPageData('csrf_token');

$(document).on('click', 'a.icon-trash[data-image-id]', function() {
  return del(this, Number(this.dataset.imageId), Number(this.dataset.userId), this.dataset.anonymousId || null);
});

function del(node,id,uid,aid){
  var tr = jQuery(node).parents("tr").first().fadeTo(1000, 0.4),
    data = {
      imageId: id,
      anonymousId: aid || null
    };

  $.ajax({
    url: pwg_getPageData('root_url') + "api/v1/users/" + uid + "/actions/delete-ratings",
    method: "POST",
    contentType: "application/json",
    data: JSON.stringify(data),
    headers: {'X-CSRF-Token': pwg_token},
    error: function(jqXHR) { tr.stop(); tr.fadeTo(0,1); alert(jqXHR.status + " " + jqXHR.statusText); },
    success: function(result){
      if (result.deletedCount)
        tr.remove();
      else
        alert(result.deletedCount);
    }
  });
  return false;
}
