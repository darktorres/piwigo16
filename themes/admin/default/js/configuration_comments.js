(function(){
  var targets = {
    'input[name="comments_validation"]' : '#email_admin_on_comment_validation',
    'input[name="user_can_edit_comment"]' : '#email_admin_on_comment_edition',
    'input[name="user_can_delete_comment"]' : '#email_admin_on_comment_deletion'
  };

  for (var selector in targets) {
    var target = targets[selector];

    jQuery(target).toggle(jQuery(selector).is(':checked'));

    (function(target){
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
