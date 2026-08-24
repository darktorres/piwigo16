export {};

(function(){
  const targets: Record<string, string> = {
    'input[name="rate"]' : '#rate_anonymous',
    'input[name="allow_user_registration"]' : '#email_admin_on_new_user',
    'input[name="email_admin_on_new_user"]' : '#email_admin_on_new_user_filter'
  };

  for (const selector in targets) {
    const target = targets[selector]!;

    jQuery(target).toggle(jQuery(selector).is(':checked'));

    // Same pre-existing closure bug as configuration_comments.ts's own
    // copy of this pattern -- `selector` read from the outer loop's
    // `var`, not passed into the IIFE the way `target` is. Preserved
    // exactly.
    (function(target){
      jQuery(selector).on('change', function() {
        jQuery(target).toggle($(this).is(':checked'));
      });
    })(target);
  };

  jQuery('.tiptip-with-img').tipTip({
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200
  });
}());

(function(){
  const max_fields = Math.ceil(pwg_getPageData('order_by_options_count')/2);

  function updateFilters() {
    const $selects = jQuery('#order_filters select');

    jQuery('#order_filters .addFilter').toggle($selects.length <= max_fields);
    jQuery('#order_filters .removeFilter').css('display', '').filter(':first').css('display', 'none');

    $selects.find('option').removeAttr('disabled');
    $selects.each(function() {
      $selects.not(this).find('option[value="'+ String(jQuery(this).val()) +'"]').attr('disabled', 'disabled');
    });
  }

  jQuery('#order_filters').on('click', '.removeFilter', function() {
    jQuery(this).parent('span.filter').remove();
    updateFilters();
  });

  jQuery('#order_filters').on('change', 'select', updateFilters);

  jQuery('#order_filters .addFilter').click(function() {
    jQuery(this).prev('span.filter').clone().insertBefore(jQuery(this));
    jQuery(this).prev('span.filter').children('select').val('');
    updateFilters();
  });

  updateFilters();
}());

jQuery(".themeBoxes a").colorbox();

jQuery("input[name='mail_theme']").change(function() {
  jQuery("input[name='mail_theme']").parents(".themeSelect").removeClass("themeDefault");
  jQuery(this).parents(".themeSelect").addClass("themeDefault");
});

jQuery("input[name='email_admin_on_new_user_filter']").change(function() {
  const val = jQuery("input[name='email_admin_on_new_user_filter']:checked").val();

  jQuery('#email_admin_on_new_user_filter_group_options').toggle(val === 'group');
});
