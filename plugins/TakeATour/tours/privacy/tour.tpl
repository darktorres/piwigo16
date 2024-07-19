{footer_script require='jquery.bootstrap-tour'}<script>

var tour = new Tour({
  name: "privacy",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=privacy" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php",
    title: "{'privacy_title1'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp1'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php",
    placement: "bottom",
    element: ".icon-help-circled",
    title: "{'privacy_title2'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp2'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=help&section=permissions",
    placement: "top",
    element: "#helpContent",
    title: "{'privacy_title3'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp3'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=help&section=permissions",
    title: "{'privacy_title4'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp4'|@translate|@escape:'javascript'}"
  },
  { //5
    path: "{$TAT_path}admin.php?page=help&section=groups",
    placement: "top",
    element: "#helpContent>p:first",
    title: "{'privacy_title5'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp5'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=photos_add",
    placement: "top",
    element: "#showPermissions",
    title: "{'privacy_title6'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp6'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-last_import",
    placement: "top",
    element: "",
    title: "{'privacy_title7'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp7'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-last_import",
    placement: "top",
    element: ".thumbnails",
    title: "{'privacy_title8'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp8'|@translate|@escape:'javascript'}",
  },
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-last_import",
    placement: "top",
    element: "#action",
    title: "{'privacy_title9'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp9'|@translate|@escape:'javascript'}"
  },
  { //10
    path: "{$TAT_path}admin.php?page=cat_list",
    placement: "top",
    title: "{'privacy_title10'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp10'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}"; },
    placement: "bottom",
    element: "#tabsheet > ul > li:nth-child(3) > a",
    reflex:true,
    title: "{'privacy_title11'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp11'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-[0-9]+-permissions/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}-permissions"; },
    placement: "top",
    element: "#categoryPermissions",
    title: "{'privacy_title12'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp12'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-[0-9]+-permissions/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}-permissions"; },
    placement: "bottom",
    element: "input[value='private']",
    reflex:true,
    title: "{'privacy_title13'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp13'|@translate|@escape:'javascript'}"
  },
  {
    path: /admin\.php\?page=album-[0-9]+-permissions/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}-permissions"; },
    placement: "top",
    element: "#privateOptions",
    title: "{'privacy_title14'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp14'|@translate|@escape:'javascript'}",
  },
  {
    path: /admin\.php\?page=album-[0-9]+-permissions/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}-permissions"; },
    title: "{'privacy_title14b'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp14b'|@translate|@escape:'javascript'}",
  },
  { //15
    path: /admin\.php\?page=album-[0-9]+-permissions/,
    redirect:function (tour) { window.location = "admin.php?page=album-{$TAT_cat_id}-permissions"; },
    element: "a[href='./admin.php?page=cat_options']",
    reflex:true,
    title: "{'privacy_title15'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp15'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=cat_options",
    placement: "top",
    element: ".doubleSelect",
    title: "{'privacy_title16'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp16'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=group_list",
    title: "{'privacy_title17'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp17'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=group_list",
    placement: "right",
    element: "a[href='./admin.php?page=user_list']",
    title: "{'privacy_title18'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp18'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=user_list",
    placement: "top",
    element: "#userList",
    title: "{'privacy_title19'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp19'|@translate|@escape:'javascript'}",

  },
  { //20
    path: "{$TAT_path}admin.php",
    title: "{'privacy_title20'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp20'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php",
    title: "{'privacy_title21'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp21'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php",
    title: "{'privacy_title22'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp22'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php",
    title: "{'privacy_title24'|@translate|@escape:'javascript'}",
    content: "{'privacy_stp24'|@translate|@escape:'javascript'}"
  }
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

jQuery( "p.albumActions a" ).click(function() {
  if (tour.getCurrentStep()==9)
  {
    tour.goTo(10);
  }
});

</script>{/footer_script}
{html_style}<style>
#step-21 {
  max-width:476px;
}
#step-22 {
  max-width:376px;
}
</style>{/html_style}