jQuery(document).ready(function(){
  jQuery(".menuPos").hide();
  jQuery(".drag_button").show();
  jQuery(".menuLi").css("cursor","move");
  jQuery(".menuUl").sortable({
    axis: "y",
    opacity: 0.8
  });
  jQuery("input[name^='hide_']").click(function() {
    var men = this.name.split('hide_');
    if (this.checked) {
      jQuery("#menu_"+men[1]).addClass('menuLi_hidden');
    } else {
      jQuery("#menu_"+men[1]).removeClass('menuLi_hidden');
    }
  });
  jQuery("#menuOrdering").submit(function(){
    var ar = jQuery('.menuUl').sortable('toArray');
    for(var i=0;i < ar.length;i++) {
      var men = ar[i].split('menu_');
      document.getElementsByName('pos_' + men[1])[0].value = i+1;
    }
  });
});
