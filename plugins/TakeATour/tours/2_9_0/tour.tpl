{footer_script require='jquery.bootstrap-tour'}

var tour = new Tour({
  name: "2_9_0",
  orphan: true,
  onEnd: function (tour) { window.location = "{$ABS_U_ADMIN}admin.php?page=plugin-TakeATour&tour_ended=2_9_0" },
  template: "<div class='popover'>          <div class='arrow'></div>          <h3 class='popover-title'></h3>          <div class='popover-content'></div>          <div class='popover-navigation'>            <div class='btn-group'>              <button class='btn btn-sm btn-default' data-role='prev'>&laquo; {'Prev'|@translate|@escape:'javascript'}</button>              <button class='btn btn-sm btn-default' data-role='next'>{'Next '|@translate|@escape:'javascript'} &raquo;</button>            </div>            <button class='btn btn-sm btn-default' data-role='end'>{'End tour'|@translate|@escape:'javascript'}</button>          </div>        </div>",
});
{if isset($TAT_restart) and $TAT_restart}tour.restart();{/if}

tour.addSteps([
  {
    path: "{$TAT_path}admin.php",
    title: "{'2_9_0_title1'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp1'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}admin.php?page=user_list&show_add_user",
    placement: "right",
    element: "#genPass",
    title: "{'2_9_0_title2'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp2'|@translate|@escape:'javascript'}"
  },
{if isset($TAT_tour29_delete_cat_id)}
  {
    path: "{$TAT_path}admin.php?page=album-{$TAT_tour29_delete_cat_id}-properties",
    placement: "right",
    element: ".deleteAlbum",
    title: "{'2_9_0_title3'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp3'|@translate|@escape:'javascript'}"
  },
{/if}
{if isset($TAT_tour29_image_id)}
  {
    path: "{$TAT_path}admin.php?page=photo-{$TAT_tour29_image_id}-properties",
    placement: "bottom",
    element: 'a.icon-download',
    title: "{'2_9_0_title4'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp4'|@translate|@escape:'javascript'}"
  },
{/if}
  {
    path: "{$TAT_path}admin.php?page=batch_manager&filter=prefilter-duplicates-checksum",
    placement: "bottom",
    element: 'label[title=md5sum]',
    title: "{'2_9_0_title5'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp5'|@translate|@escape:'javascript'}"
  },
  {
    path: "{$TAT_path}{$TAT_tour29_history_url}",
    placement: "bottom",
    element: "#content h3 a:last",
    title: "{'2_9_0_title6'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp6'|@translate|@escape:'javascript'}"
  },
{if isset($TAT_tour29_has_tags)}
  {
    path: "{$TAT_path}admin.php?page=tags",
    placement: "right",
    element: "#selectionMode",
    title: "{'2_9_0_title7'|@translate|@escape:'javascript'}",
    content: "{'2_9_0_stp7'|@translate|@escape:'javascript'}"
  },
{/if}
]);

// Initialize the tour
tour.init();

// Start the tour
tour.start();

{/footer_script}