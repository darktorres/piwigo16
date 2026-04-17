{footer_script}<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.cluetip').forEach(function(el) {
      var raw = el.getAttribute('title') || '';
      el.removeAttribute('title');
      el.dataset.cluetip = raw;
      el.addEventListener('mouseenter', function() {
        var parts = (el.dataset.cluetip || '').split('|');
        var tip = document.createElement('div');
        tip.className = 'pwg-cluetip';
        tip.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;padding:8px;width:300px;box-shadow:2px 2px 6px rgba(0,0,0,.2);font-size:13px;';
        tip.innerHTML = '<strong>' + (parts[0] || '') + '</strong>' + (parts[1] ? '<hr style="margin:4px 0">' + parts[1] : '');
        document.body.appendChild(tip);
        var rect = el.getBoundingClientRect();
        tip.style.left = (rect.left + window.scrollX) + 'px';
        tip.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        el._cluetip = tip;
      });
      el.addEventListener('mouseleave', function() {
        if (el._cluetip) { el._cluetip.remove(); el._cluetip = null; }
      });
    });
  });
</script>{/footer_script}

{if $isWebmaster == 1}

  {if !empty($languages)}
    <table class="table2 languages">
      <thead>
        <tr class="throw">
          <td>{'Language'|translate}</td>
          <td>{'Version'|translate}</td>
          <td>{'Date'|translate}</td>
          <td>{'Author'|translate}</td>
          <td>{'Actions'|translate}</td>
        </tr>
      </thead>
      {foreach from=$languages item=language name=languages_loop}
        <tr class="{if $smarty.foreach.languages_loop.index is odd}row1{else}row2{/if}">
          <td><a href="{$language.EXT_URL}" class="externalLink cluetip"
              title="{$language.EXT_NAME}|{$language.EXT_DESC|htmlspecialchars|nl2br}">{$language.EXT_NAME}</a></td>
          <td style="text-align:center;"><a href="{$language.EXT_URL}" class="externalLink cluetip"
              title="{$language.EXT_NAME}|{$language.VER_DESC|htmlspecialchars|nl2br}">{$language.VERSION}</a></td>
          <td>{$language.DATE}</td>
          <td>{$language.AUTHOR}</td>
          <td style="text-align:center;"><a href="{$language.URL_INSTALL}">{'Install'|translate}</a>
            / <a href="{$language.URL_DOWNLOAD}">{'Download'|translate}</a>
          </td>
        </tr>
      {/foreach}
    </table>
  {else}
    <p>{'There is no other language available.'|translate}</p>
  {/if}

{/if}