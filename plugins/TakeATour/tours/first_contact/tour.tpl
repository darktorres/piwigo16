{footer_script require='jquery.bootstrap-tour'  load="async"}

var tour = new Tour({
  name: "first_contact",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=first_contact" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php",
    title: "{'first_contact_title1'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp1'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php",
    placement: "right",
    element: "#menubar a[href='./admin.php?page=photos_add']",
    reflex:true,
    title: "{'first_contact_title2'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp2'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "bottom",
    element: ".selected_tab",
    title: "{'first_contact_title3'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp3'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "right",
    element: "#albumSelection",
    title: "{'first_contact_title4'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp4'|@translate|@escape:'javascript'}"
  },
  { //5
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "top",
    element: "#addFiles",
    title: "{'first_contact_title5'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp5'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "top",
    element: "#startUpload",
    title: "{'first_contact_title6'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp6'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "top",
    element: "#afterUploadActions",
    title: "{'first_contact_title7'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp7'|@translate|@escape:'javascript'}",
    prev:3,
    onPrev: function (tour) { window.location.reload() }
  }
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

{/footer_script}
