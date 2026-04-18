{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

<script id="pwg-page-data" type="application/json">
{
  "categoriesServerKey": "{$CACHE_KEYS.categories|escape:'html'}",
  "categoriesServerId": "{$CACHE_KEYS._hash|escape:'html'}",
  "rootUrl": "{$ROOT_URL|escape:'html'}",
  "nbElements": {$NB_ELEMENTS}
}
</script>

{if $vite_rating}
<script type="module" src="admin/themes/default/js/dist/{$vite_rating}"></script>
{/if}

<form action="{$F_ACTION}" method="GET" class="filter">
  <fieldset>
    <legend><span class="icon-filter icon-green"></span>{'Filter'|translate}</legend>

    <label>
      {'Sort by'|translate}
      <select name="order_by">
        {html_options options=$order_by_options selected=$order_by_options_selected}
      </select>
    </label>

    <label>
      {'Users'|translate}
      <select name="users">
        {html_options options=$user_options selected=$user_options_selected}
      </select>
    </label>

    <label>
      {'Number of items'|translate}
      <input type="text" name="display" size="2" value="{$DISPLAY}">
    </label>

    <label>
      {'Album'|translate}<a href="#" id="removeAlbumFilter" class="icon-cancel-circled"></a>
      <select data-selectize="categories" data-value="{$category|json_encode|escape:html}"
        placeholder="{'No filter on album. Select one or type to search'|translate}" name="cat"
        style="width:400px"></select>
    </label>

    <div style="clear:both"></div>

    <p style="margin:10px 0 0 0">
      <button name="submit" type="submit" class="buttonLike">
        <i class="icon-filter"></i> {'Submit'|translate}
      </button>
    </p>
    <input type="hidden" name="page" value="rating">
  </fieldset>
</form>

{if !empty($navbar) }{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}

<table width="99%">
  <tr class="throw">
    <td>{'File'|translate}</td>
    <td>{'Number of rates'|translate}</td>
    <td>{'Rating score'|translate}</td>
    <td>{'Average rate'|translate}</td>
    <td>{'Sum of rates'|translate}</td>
    <td>{'Rate'|translate}/{'Username'|translate}/{'Rate date'|translate}</td>
    <td></td>
  </tr>
  {foreach from=$images item=image name=image}
    <tr valign="top" class="{if $smarty.foreach.image.index is odd}row1{else}row2{/if}">
      <td><a href="{$image.U_URL}"><img src="{$image.U_THUMB}" alt="{$image.FILE}" title="{$image.FILE}"></a></td>
      <td><strong>{$image.NB_RATES}/{$image.NB_RATES_TOTAL}</strong></td>
      <td><strong>{$image.SCORE_RATE}</strong></td>
      <td><strong>{$image.AVG_RATE}</strong></td>
      <td style="border-right:1px solid"><strong>{$image.SUM_RATE}</strong></td>
      <td>
        <table style="width:100%">
          {foreach $image.rates as $rate}
            <tr>
              <td>{$rate.rate}</td>
              <td><b>{$rate.USER}</b></td>
              <td>{$rate.date}</td>
              <td><a
                  onclick="return del(this,{$image.id},{$rate.user_id}{if !empty({$rate.anonymous_id})},'{$rate.anonymous_id}'{/if})"
                  class="icon-trash"> </a></td>
            </tr>
          {/foreach}{*rates*}
        </table>
      </td>
    </tr>
  {/foreach}{*images*}
</table>

{if !empty($navbar)}{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}