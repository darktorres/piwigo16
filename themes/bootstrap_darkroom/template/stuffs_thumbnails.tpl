{assign var='thumbnails' value=$block.thumbnails}
{assign var='derivative_params' value=$block.derivative_params}
<div class="row pwgstuffs-thumbnails">
  {include file='thumbnails.tpl'|get_extent:'index_thumbnails'}
</div>

{footer_script}<script>
  document.addEventListener('DOMContentLoaded', function() {
    var thumbnailsDiv = document.querySelector('.pwgstuffs-thumbnails');
    if (!thumbnailsDiv) return;

    var elm = thumbnailsDiv.closest('.pwgstuffs-col');
    if (!elm) return;

    var colOuters = document.querySelectorAll('.pwgstuffs-thumbnails .col-outer');
    var gridClasses = colOuters.length > 0 ? colOuters[0].dataset.gridClasses : '';

    if (elm.classList.contains('col-lg-3') || elm.classList.contains('col-lg-4')) {
      colOuters.forEach(function(el) {
        if (gridClasses) el.classList.remove(...gridClasses.split(' '));
        el.classList.add('col-12');
      });
    } else if (elm.classList.contains('col-lg-6')) {
      colOuters.forEach(function(el) {
        if (gridClasses) el.classList.remove(...gridClasses.split(' '));
        el.classList.add('col-12', 'col-lg-6');
      });
    } else if (elm.classList.contains('col-lg-8') || elm.classList.contains('col-lg-9')) {
      colOuters.forEach(function(el) {
        if (gridClasses) el.classList.remove(...gridClasses.split(' '));
        el.classList.add('col-12', 'col-lg-4');
      });
    }
  });
</script>{/footer_script}