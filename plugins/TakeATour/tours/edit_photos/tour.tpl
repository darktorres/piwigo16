{footer_script require='jquery.bootstrap-tour'  load="async"}<script>
  var tour = new Tour({
    name: "edit_photos",
    orphan: true,
    onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=edit_photos" },
    template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
  });
  {if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

  tour.addSteps([{
      path: /admin\.php\?page=(photos_add|batch_manager&filter=prefilter-last_import|batch_manager&filter=prefilter-caddie)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "bottom",
      element: "#filter_prefilter select",
      title: "{'first_contact_title9'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp9'|@translate|@escape:'javascript'}",
      prev: 3,
      onPrev: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=photos_add"}
    },
    { //10
      path: /admin\.php\?page=batch_manager&filter=(prefilter-caddie|prefilter-last_import)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "right",
      element: "a[href='./admin.php?page=batch_manager&filter=prefilter-caddie']",
      title: "{'first_contact_title10'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp10'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=batch_manager&filter=(prefilter-caddie|prefilter-last_import)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "left",
      element: "#checkActions",
      title: "{'first_contact_title11'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp11'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=batch_manager&filter=(prefilter-caddie|prefilter-last_import)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "top",
      element: "#action",
      title: "{'first_contact_title12'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp12'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=batch_manager&filter=(prefilter-caddie|prefilter-last_import)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "bottom",
      element: "#tabsheet .normal_tab",
      title: "{'first_contact_title13'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp13'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=batch_manager&filter=(prefilter-caddie|prefilter-last_import)/,
      redirect: function(tour) { window.location = "admin.php?page=batch_manager&filter=prefilter-last_import"; },
      placement: "top",
      element: "#TAT_FC_14",
      reflex: true,
      title: "{'first_contact_title14'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp14'|@translate|@escape:'javascript'}",
      onNext:function (tour) { window.location = "admin.php?page=photo-{$TAT_image_id}"; }
    },
    { //15
      path: /admin\.php\?page=photo-/,
      redirect:function (tour) { window.location = "admin.php?page=photo-{$TAT_image_id}"; },
      placement: "bottom",
      element: ".selected_tab",
      title: "{'first_contact_title15'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp15'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=photo-/,
      redirect:function (tour) { window.location = "admin.php?page=photo-{$TAT_image_id}"; },
      placement: "top",
      element: "#TAT_FC_16",
      title: "{'first_contact_title16'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp16'|@translate|@escape:'javascript'}"
    },
    {
      path: /admin\.php\?page=photo-/,
      redirect:function (tour) { window.location = "admin.php?page=photo-{$TAT_image_id}"; },
      placement: "top",
      element: "#TAT_FC_17",
      title: "{'first_contact_title17'|@translate|@escape:'javascript'}",
      content: "{'first_contact_stp17'|@translate|@escape:'javascript'}"
    }
  ]);

  // Initialize the tour
  tour.init();

  // Start the tour
  tour.start();

  jQuery("p.albumActions > a:nth-child(1)").click(function() {
    if (tour.getCurrentStep() == 20) {
      tour.goTo(21);
    }
  });
</script>{/footer_script}