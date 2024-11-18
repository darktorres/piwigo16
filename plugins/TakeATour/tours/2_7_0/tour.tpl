{footer_script require='jquery.bootstrap-tour'}<script>

var tour = new Tour({
  name: "2_7_0",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=2_7_0" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php",
    title: "{'2_7_0_title1'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp1'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=plugin-TakeATour",
    placement: "left",
    element: "#content",
    title: "{'2_7_0_title2'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp2'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "top",
    title: "{'2_7_0_title2b'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp2b'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}{$TAT_search}",
    placement: "left",
    element: "#content",
    title: "{'2_7_0_title4'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp4'|@translate|@escape:'javascript'}"
  },
  { //5
    path: "{$TAT_path}admin.php?page=photo-{$TAT_image_id}",
    placement: "top",
    element: ".icon-calendar",
    title: "{'2_7_0_title5'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp5'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=photo-{$TAT_image_id}",
    placement: "top",
    element: "#catModify > fieldset:nth-child(2) > p:nth-child(5) > strong",
    title: "{'2_7_0_title6'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp6'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=cat_list",
    element: "#autoOrderOpen",
    onShown: function (tour) { jQuery("#autoOrderOpen").trigger("click"); },
    title: "{'2_7_0_title7'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp7'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-caddie",
    element: "#empty_caddie",
    placement: "right",
    title: "{'2_7_0_title8'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp8'|@translate|@escape:'javascript'}",
    prev:4
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=search-taken:2013..2015",
    element: "#filter_search input[name=q]",
    title: "{'2_7_0_title9'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp9'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=filesize-1..5",
    element: "#filter_filesize",
    placement: "top",
    title: "{'2_7_0_title10'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp10'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=plugin-TakeATour",
    title: "{'2_7_0_title11'|@translate|@escape:'javascript'}",
    content: "{'2_7_0_stp11'|@translate|@escape:'javascript'}"
  }
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

jQuery( "input[class='submit']" ).click(function() {
  if (tour.getCurrentStep()==5)
  {
    tour.goTo(6);
  }
});
</script>{/footer_script}