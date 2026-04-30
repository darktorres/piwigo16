{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

{footer_script}

const cat_nav = '{$CATEGORIES_NAV|escape:javascript}';

function checkWhoOptions() {
  var checked = document.querySelector("input[name=who]:checked");
  var option = checked ? checked.value : '';
  document.querySelectorAll(".who_option").forEach(function(el) { el.style.display = 'none'; });
  if (option) {
    document.querySelectorAll(".who_" + option).forEach(function(el) { el.style.display = ''; });
  }
}

document.querySelectorAll("input[name=who]").forEach(function(el) {
  el.addEventListener('change', checkWhoOptions);
});
checkWhoOptions();

document.querySelector("form#categoryNotify")?.addEventListener('submit', function(e) {
  var checked = document.querySelector("input[name=who]:checked");
  var who_option = checked ? checked.value : '';
  var who_selected = false;
  var selEl = document.querySelector(".who_" + who_option + " select");
  if (selEl && selEl.querySelectorAll("option:checked").length > 0) {
    who_selected = true;
  }
  var errEl = document.querySelector(".actionButtons .errors");
  if (!who_selected) {
    if (errEl) errEl.style.display = '';
    e.preventDefault();
  } else {
    if (errEl) errEl.style.display = 'none';
  }
});
{/footer_script}

{html_style}
.who_option {
  margin-top:5px;
}

span.errors {
  background-image:none;
  padding:2px 5px;
  margin:0;
  border-radius:5px;
}
{/html_style}

<form action="{$F_ACTION}" method="post" id="categoryNotify">
<input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">

<fieldset id="emailCatInfo">
  <legend><span class="icon-mail-1 icon-green"></span>{'Send mail to users'|@translate}</legend>

  <p>
    <strong>{'Recipients'|@translate}</strong>
    <label class="font-checkbox">
      <span class="icon-dot-circled"></span>
      <input type="radio" name="who" value="group" checked="checked">
      {'Group'|translate}
    </label>

    <label class="font-checkbox">
      <span class="icon-dot-circled"></span>
      <input type="radio" name="who" value="users">
      {'Users'|translate}
    </label>
  </p>

  <p class="who_option who_group">
{if isset($group_mail_options)}
    <select name="group" placeholder="{'Type in a search term'|translate}" style="width:524px;">
      {html_options options=$group_mail_options}
    </select>
{elseif isset($no_group_in_gallery) and $no_group_in_gallery}
    {'There is no group in this gallery.'|@translate} <a href="admin.php?page=group_list" class="externalLink">{'Group management'|@translate}</a>
{else}
    {'No group is permitted to see this private album'|@translate}.
    <a href="{$permission_url}" class="externalLink">{'Permission management'|@translate}</a>
{/if}
    </p>

    <p class="who_option who_users">
{if isset($user_options)}
    <select name="users[]" multiple placeholder="{'Type in a search term'|translate}" style="width:524px;">
      {html_options options=$user_options}
    </select>
{else}
    {'No user is permitted to see this private album'|@translate}.
    <a href="{$permission_url}" class="externalLink">{'Permission management'|@translate}</a>
{/if}
    </p>

  <p>
    <strong>{'Complementary mail content'|@translate}</strong>
    <br>
<textarea cols="50" rows="5" name="mail_content" id="mail_content" class="description">{if isset($MAIL_CONTENT)}{$MAIL_CONTENT}{/if}</textarea>
  </p>

{if isset($auth_key_duration)}
  <p>
  {'Each email sent will contain its own automatic authentication key on links, valid for %s.'|translate:$auth_key_duration}
  <br>{'For security reason, authentication keys do not work for administrators.'|translate}
  </p>
{/if}

  <div class="savebar-footer">
    <div class="savebar-footer-start">
    </div>
    <div class="savebar-footer-end">

{if isset($save_success)}
      <div class="savebar-footer-block">
        <div class="badge info-message">
          <i class="icon-ok"></i>{$save_success}
        </div>
      </div>
{/if}
    
      <div class="savebar-footer-block">
        <button class="buttonLike" type="submit" name="submitEmail"><i class="icon-mail"></i> {'Send'|@translate}</button>
      </div>
    </div>
  </div>

</fieldset>

</form>
