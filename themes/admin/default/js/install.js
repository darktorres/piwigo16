$(document).ready(function() {
  $("a.externalLink").click(function() {
    window.open($(this).attr("href"));
    return false;
  });

  $("#admin_mail").keyup(function() {
    $(".adminEmail").text($(this).val());
  });
});

jQuery().ready(function(){
  jQuery('.cluetip').cluetip({
    width: 300,
    splitTitle: '|',
    positionBy: 'bottomTop'
  });
});
