export {};

// Update preview when user clicks on mini previews
jQuery(".std_pgs_mini_previews img").click(function () {

  //Make selected skin outlined
  jQuery(".std_pgs_mini_previews img").removeClass('selected');
  jQuery(this).addClass('selected');

  //Update preview when useer clicks on mini
  jQuery('input[name=std_pgs_selected_skin]').val(jQuery(this).attr('id')!);

  const preview_light_path = "themes/standard_pages/skins/light-"+$(this).attr('id')+".jpg";
  const preview_dark_path = "themes/standard_pages/skins/dark-"+$(this).attr('id')+".jpg";

  jQuery('.std_pgs_selected_preview img#preview-light').attr("src", preview_light_path);
  jQuery('.std_pgs_selected_preview img#preview-dark').attr("src", preview_dark_path);
});

jQuery("input[name=std_pgs_display_logo]").click(function () {
  if(jQuery(this).val() === 'custom_logo')
  {
    // jQuery('#std_pgs_logo').addClass('show').removeClass('hide');
    jQuery('.custom_logo_preview').addClass('show').removeClass('hide');
  }
  else
  {
    // jQuery('#std_pgs_logo').addClass('hide').removeClass('show');
    jQuery('.custom_logo_preview').addClass('hide').removeClass('show');
  }
});

// Scroll mini to show the selected one
jQuery(document).ready(function () {
  const std_pgs_mini_previews = jQuery('.std_pgs_mini_previews');
  const selected_mini = std_pgs_mini_previews.find('.selected');

  if (selected_mini.length) {
    std_pgs_mini_previews.scrollTop(
      selected_mini.position().top + std_pgs_mini_previews.scrollTop()!
    );
  }
});

//Switch between change logo and use existing logo

  jQuery('#change_logo').click(function () {
    jQuery('.use_existing_logo_container').show();
    jQuery('.change_logo_container').hide();
  });
  jQuery('#use_existing_logo').click(function () {
    jQuery('.change_logo_container').show();
    jQuery('.use_existing_logo_container').hide();
    jQuery('#std_pgs_logo').val('');
  });
