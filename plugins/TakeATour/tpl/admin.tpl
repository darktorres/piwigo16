{combine_css path='plugins/TakeATour/css/admin_page.css' order=-10}
{footer_script require="tippy.js"}<script>
  document.querySelectorAll('.TAT_description a[href*="piwigo.org"]').forEach(function(el) {
    el.classList.add("externalLink");
  });

  tippy('.showInfo', { delay: 0, maxWidth: 300, interactive: true, trigger: 'click', placement: 'top' });

  var showDetailsLink = document.querySelector(".showDetails a");
  if (showDetailsLink) {
    showDetailsLink.addEventListener("click", function(e) {
      e.preventDefault();
      document.querySelectorAll(".pluginMiniBox, .pluginBox").forEach(function(el) {
        el.style.display = el.style.display === 'none' ? '' : 'none';
      });
      var altText = this.dataset.altText;
      this.dataset.altText = this.innerHTML;
      this.innerHTML = altText;
      this.classList.toggle("icon-eye");
      this.classList.toggle("icon-eye-off");
    });
  }
</script>{/footer_script}

<div class="titrePage">
  <h2>{'takeatour_configpage'|translate}</h2>
</div>
<div id="helpContent">
  <p>{'TAT_descrp'|translate}</p>

  <fieldset style="text-align:left">
    <legend>List of Tours</legend>

    <div class="showDetails">
      <a href="#" class="icon-eye" data-alt-text="{'hide details'|translate|escape:html}">{'show details'|translate}</a>
    </div>

    {foreach from=$tours item=tour name=tours_loop}
      <div id="{$tour.id}" class="pluginMiniBox">
        <div class="pluginMiniBoxNameCell">
          {$tour.name}
          <a class="icon-info-circled-1 showInfo" title="{$tour.desc|escape:'html'}"></a>
        </div>
        <div class="pluginActions">
          <div>
            <a href="{$F_ACTION}?submitted_tour_path=tours/{$tour.id}&amp;pwg_token={$pwg_token}">{'Start the Tour'|translate}
              <i class="icon-right"></i></a>
          </div>
        </div>
      </div> {*<!-- pluginMiniBox -->*}

      <div id="{$tour.id}" class="pluginBox">
        <table>
          <tr>
            <td class="pluginBoxNameCell">
              {$tour.name}
            </td>
            <td rowspan="2">{$tour.desc}</td>
          </tr>
          <tr class="pluginActions">
            <td>
              <a href="{$F_ACTION}?submitted_tour_path=tours/{$tour.id}&amp;pwg_token={$pwg_token}">{'Start the Tour'|translate}
                <i class="icon-right"></i></a>
            </td>
          </tr>
        </table>
      </div> {*<!-- pluginBox -->*}

    {/foreach}
  </fieldset>
</div>