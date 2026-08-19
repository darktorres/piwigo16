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
