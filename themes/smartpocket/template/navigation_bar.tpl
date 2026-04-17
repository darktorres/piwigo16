<div class="ui-bar ui-bar-a" style="text-align:center;">
  <div>
    {if isset($navbar.URL_PREV)}
      <a href="{$navbar.URL_PREV}" rel="prev">{'Previous'|translate}</a>
    {/if}
    <a href="#">{$navbar.CURRENT_PAGE} / {$navbar.NB_PAGE}&nbsp;</a>
    {if isset($navbar.URL_NEXT)}
      <a href="{$navbar.URL_NEXT}" rel="next">{'Next'|translate}</a>
    {/if}
  </div>
</div>