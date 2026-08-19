jQuery(document).ready(function() {
  jQuery("#authors, #tags, #categories").each(function() {
    jQuery(this).selectize({
      plugins: ['remove_button'],
      maxOptions:jQuery(this).find("option").length
    });
  })
});
