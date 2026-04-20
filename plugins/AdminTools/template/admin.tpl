{combine_css path='plugins/AdminTools/template/admin.css' order=-10}


{footer_script}<script type="module" src="{$ROOT_URL}admin/themes/default/js/dist/{$vite_at_admin}"></script>{/footer_script}

<div class="titrePage">
  <h2>Admin Tools</h2>
</div>

<form method="post" action="" class="properties" id="ato-config">
  <fieldset>
    <legend>{'Configuration'|translate}</legend>
    <ul>
      <li>
        <label>
          <span class="graphicalCheckbox icon-check{if not $AdminTools.default_open}-empty{/if}"></span>
          <input type="checkbox" name="default_open" {if $AdminTools.default_open} checked="checked" {/if}>
          <b>{'Open toolbar by default'|translate}</b>
        </label>
      </li>
      <li>
        <label>
          <span class="graphicalCheckbox icon-check{if not $AdminTools.public_quick_edit}-empty{/if}"></span>
          <input type="checkbox" name="public_quick_edit" {if $AdminTools.public_quick_edit} checked="checked" {/if}>
          <b>{'Give access to quick edit to photo owners even if they are not admin'|translate}</b>
        </label>
      </li>
      <li>
        <b>{'Closed icon position'|translate} :</b>
        <label>
          <span class="graphicalCheckbox icon-check{if $AdminTools.closed_position!='left'}-empty{/if}"></span>
          <input type="radio" name="closed_position" value="left" {if $AdminTools.closed_position=='left'}
            checked="checked" {/if}>
          {'left'|translate}
        </label>
        <label>
          <span class="graphicalCheckbox icon-check{if $AdminTools.closed_position!='right'}-empty{/if}"></span>
          <input type="radio" name="closed_position" value="right" {if $AdminTools.closed_position=='right'}
            checked="checked" {/if}>
          {'right'|translate}
        </label>
      </li>
    </ul>
  </fieldset>

  <p class="formButtons"><input type="submit" name="save_config" value="{'Save Settings'|translate}"></p>
</form>