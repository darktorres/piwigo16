{footer_script require='jquery.bootstrap-tour'  load="async"}<script>
  var tour = new Tour({
    name: "config",
    orphan: true,
    onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=config" },
    template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
  });
  {if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

  tour.addSteps([{
      path: "{$TAT_path}admin.php?page=configuration",
      placement: "top",
      element: "",
      title: "{'first_contact_title29'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp29'|@translate|@escape:'javascript'}"
    },
    { //30
      path: "{$TAT_path}admin.php?page=configuration",
      placement: "right",
      element: "#gallery_title",
      title: "{'first_contact_title30'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp30'|@translate|@escape:'javascript'}"
    },
    {
      path: "{$TAT_path}admin.php?page=configuration",
      placement: "right",
      element: "#page_banner",
      title: "{'first_contact_title31'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp31'|@translate|@escape:'javascript'}"
    },
    {
      path: "{$TAT_path}admin.php?page=configuration",
      reflex: true,
      placement: "top",
      element: ".formButtons input",
      title: "{'first_contact_title32'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp32'|@translate|@escape:'javascript'}"
    },
    {
      path: "{$TAT_path}admin.php?page=configuration",
      placement: "bottom",
      element: "li.normal_tab:nth-child(6) > a:nth-child(1)",
      title: "{'first_contact_title33'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp33'|@translate|@escape:'javascript'}",
      prev: 30
    },
    {
      path: "{$TAT_path}admin.php?page=themes",
      placement: "top",
      element: "",
      title: "{'first_contact_title34'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp34'|@translate|@escape:'javascript'}"
    },
    { //35
      path: "{$TAT_path}admin.php?page=themes",
      placement: "top",
      element: "#TAT_FC_35",
      title: "{'first_contact_title35'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp35'|@translate|@escape:'javascript'}"
    }
  ]);

  // Initialize the tour
  tour.init();

  // Start the tour
  tour.start();
</script>{/footer_script}