{footer_script require='jquery.bootstrap-tour'}<script>

var tour = new Tour({
  name: "2_8_0",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=2_8_0" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php?page=user_list",
    title: "{'2_8_0_title1'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp1'|@translate|@escape:'javascript'}"
  },
{if isset($TAT_cat_id)}
  {
    path: "{$TAT_path}admin.php?page=album-{$TAT_cat_id}-notification",
    placement: "right",
    element: "select[name=who]",
    title: "{'2_8_0_title2'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp2'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=album-{$TAT_cat_id}-notification",
    placement: "top",
    element: "#emailCatInfo p:nth-child(6)",
    title: "{'2_8_0_title3'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp3'|@translate|@escape:'javascript'}"
  },
{/if}
  {
    path: "{$TAT_path}admin.php?page=configuration&section=watermark",
    placement: "right",
    element: 'input[name="w[yrepeat]"]',
    title: "{'2_8_0_title4'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp4'|@translate|@escape:'javascript'}"
  },
{if $TAT_HAS_ORPHANS}
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-no_album",
    placement: "right",
    element: '#menubar dl:first .adminMenubarCounter:last',
    title: "{'2_8_0_title5'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp5'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-no_album",
    placement: "bottom",
    element: '#delete_orphans',
    title: "{'2_8_0_title6'|@translate|@escape:'javascript'}",
    content: "{'2_8_0_stp6'|@translate|@escape:'javascript'}"
  },
{/if}
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

if (tour.getCurrentStep() == 3) {
  jQuery("input[value=custom]").prop("checked", true);
}

</script>{/footer_script}