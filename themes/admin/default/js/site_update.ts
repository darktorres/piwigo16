export {};

$('#syncFiles label').click(function () {
  if ($("input[value='files']:checked").val()) {
    $("input[value='files']").closest("li").find("ul").show();
  } else {
    $("input[value='files']").closest("li").find("ul").hide();
  }
})
