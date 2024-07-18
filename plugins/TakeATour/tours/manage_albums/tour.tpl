{footer_script require='jquery.bootstrap-tour'  load="async"}

var tour = new Tour({
  name: "manage_albums",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=manage_albums" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php?page=cat_list",
    title: "{'first_contact_title19'|@translate|@escape:'javascript'}",
    content: "{if $TAT_FTP}{'first_contact_stp19'|@translate|@escape:'javascript'}{else}{'first_contact_stp19_b'|@translate|@escape:'javascript'}{/if}",
    onPrev: function (tour) { window.location = "admin.php?page=photo-{$TAT_image_id}"; },

  },
  { //20
    path: "{$TAT_path}admin.php?page=cat_list",
    placement: "top",
    element: "#categoryOrdering",
    title: "{'first_contact_title20'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp20'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=cat_list",
    placement: "left",
    element: "#tabsheet:first-child",
    title: "{'first_contact_title21'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp21'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-[0-9]+(|-properties)$/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}"; },
    placement: "top",
    element: ".selected_tab",
    title: "{'first_contact_title22'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp22'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-[0-9]+(|-properties)$/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}"; },
    placement: "top",
    element: "#TAT_FC_23",
    title: "{'first_contact_title23'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp23'|@translate|@escape:'javascript'}"
  }
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

jQuery( "p.albumActions > a:nth-child(1)" ).click(function() {
  if (tour.getCurrentStep()==2)
  {
    tour.goTo(3);
  }
});


{/footer_script}
