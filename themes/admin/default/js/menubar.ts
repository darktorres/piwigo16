export {};

jQuery(document).ready(function(){
  jQuery(".menuPos").hide();
  jQuery(".drag_button").show();
  jQuery(".menuLi").css("cursor","move");
  jQuery(".menuUl").sortable({
    axis: "y",
    opacity: 0.8
  });
  jQuery("input[name^='hide_']").click(function() {
    const men = (this as HTMLInputElement).name.split('hide_');
    if ((this as HTMLInputElement).checked) {
      jQuery("#menu_"+men[1]!).addClass('menuLi_hidden');
    } else {
      jQuery("#menu_"+men[1]!).removeClass('menuLi_hidden');
    }
  });
  jQuery("#menuOrdering").submit(function(){
    const ar = jQuery('.menuUl').sortable('toArray');
    for(let i=0;i < ar.length;i++) {
      const men = ar[i].split('menu_');
      (document.getElementsByName('pos_' + men[1])[0] as HTMLInputElement).value = String(i+1);
    }
  });
});
