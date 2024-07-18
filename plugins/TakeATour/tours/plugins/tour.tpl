{footer_script require='jquery.bootstrap-tour'  load="async"}

var tour = new Tour({
  name: "plugins",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=plugins" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php?page=plugins",
    placement: "left",
    element: "",
    title: "{'first_contact_title38'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp38'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=plugins",
    placement: "left",
    element: "#content",
    title: "{'first_contact_title39'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp39'|@translate|@escape:'javascript'}"
  },
  { //40
    path: "{$TAT_path}admin.php?page=plugins",
    placement: "bottom",
    element: "#TakeATour",
    title: "{'first_contact_title40'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp40'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=plugins",
    placement: "right",
    element: ".tabsheet",
    title: "{'first_contact_title41'|@translate|@escape:'javascript'}",
    content: "{'first_contact_stp41'|@translate|@escape:'javascript'}"
  }
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

{/footer_script}
