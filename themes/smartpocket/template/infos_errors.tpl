{if isset($errors)}
  <div class="ui-bar ui-bar-e errors">
    <h3>{'Error'|translate}</h3>
    <div><a href="#" class="close-button">Button</a></div>
    <p>{$errors|join:'<br>'}</p>
  </div>
{/if}

{if not empty($infos)}
  <div class="ui-bar ui-bar-b infos">
    <h3>{'Info'|translate}</h3>
    <div><a href="#" class="close-button">Button</a></div>
    <p>{$infos|join:'<br>'}</p>
  </div>
{/if}

{footer_script}<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.close-button').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var bar = this.closest('.ui-bar');
        if (bar) bar.remove();
      });
    });
  });
</script>{/footer_script}