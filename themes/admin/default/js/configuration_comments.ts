export {};

(function(){
  const targets: Record<string, string> = {
    'input[name="comments_validation"]' : '#email_admin_on_comment_validation',
    'input[name="user_can_edit_comment"]' : '#email_admin_on_comment_edition',
    'input[name="user_can_delete_comment"]' : '#email_admin_on_comment_deletion'
  };

  for (const selector in targets) {
    const target = targets[selector]!;

    jQuery(target).toggle(jQuery(selector).is(':checked'));

    (function(target){
      // Genuine pre-existing closure bug: `selector` is read from the
      // outer `for...in` loop's own `var`, not passed into this IIFE
      // the way `target` is -- by the time this change handler fires,
      // `selector` holds whatever its last loop iteration left it as,
      // not the value at the time this handler was registered.
      // Preserved exactly (same code, same behavior) -- not something
      // P46's mechanical conversion is scoped to fix.
      jQuery(selector).on('change', function() {
        jQuery(target).toggle($(this).is(':checked'));
      });
    })(target);
  };

  function check_activate_comments() {
    jQuery("#comments_param_container").toggle(jQuery("input[name=activate_comments]").is(":checked"));
  }
  check_activate_comments();
  jQuery("input[name=activate_comments]").on("change", function() {
    check_activate_comments();
  });
}());
