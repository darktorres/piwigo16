{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='tom-select' load='footer' path='node_modules/tom-select/dist/js/tom-select.complete.js'}
{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

{footer_script}<script>
  const cat_nav = '{$CATEGORIES_NAV|escape:javascript}';

  document.addEventListener('DOMContentLoaded', function() {
    function checkWhoOptions() {
      var checkedEl = document.querySelector("input[name=who]:checked");
      var option = checkedEl ? checkedEl.value : '';
      document.querySelectorAll(".who_option").forEach(function(el) { el.style.display = 'none'; });
      var target = document.querySelector(".who_" + option);
      if (target) target.style.display = '';
    }

    document.querySelectorAll("input[name=who]").forEach(function(el) {
      el.addEventListener('change', function() { checkWhoOptions(); });
    });

    checkWhoOptions();

    document.querySelectorAll(".who_option select").forEach(function(el) {
      new TomSelect(el, { plugins: ['remove_button'] });
    });

    var categoryNotify = document.getElementById("categoryNotify");
    if (categoryNotify) {
      categoryNotify.addEventListener('submit', function(e) {
        var who_selected = false;
        var checkedEl = document.querySelector("input[name=who]:checked");
        var who_option = checkedEl ? checkedEl.value : '';
        var selectEl = document.querySelector(".who_" + who_option + " select");
        if (selectEl && selectEl.querySelector("option:checked")) {
          who_selected = true;
        }
        var errorsEl = document.querySelector(".actionButtons .errors");
        if (!who_selected) {
          if (errorsEl) errorsEl.style.display = '';
          e.preventDefault();
        } else {
          if (errorsEl) errorsEl.style.display = 'none';
          console.log("form can be submitted");
        }
      });
    }
  });
</script>{/footer_script}

{html_style}<style>
  .who_option {
    margin-top: 5px;
  }

  span.errors {
    background-image: none;
    padding: 2px 5px;
    margin: 0;
    border-radius: 5px;
  }
</style>{/html_style}

<form action="{$F_ACTION}" method="post" id="categoryNotify">

  <fieldset id="emailCatInfo">
    <legend><span class="icon-mail-1 icon-green"></span>{'Send mail to users'|translate}</legend>

    <p>
      <strong>{'Recipients'|translate}</strong>
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
        {'There is no group in this gallery.'|translate} <a href="admin.php?page=group_list"
          class="externalLink">{'Group management'|translate}</a>
      {else}
        {'No group is permitted to see this private album'|translate}.
        <a href="{$permission_url}" class="externalLink">{'Permission management'|translate}</a>
      {/if}
    </p>

    <p class="who_option who_users">
      {if isset($user_options)}
        <select name="users[]" multiple placeholder="{'Type in a search term'|translate}" style="width:524px;">
          {html_options options=$user_options}
        </select>
      {else}
        {'No user is permitted to see this private album'|translate}.
        <a href="{$permission_url}" class="externalLink">{'Permission management'|translate}</a>
      {/if}
    </p>

    <p>
      <strong>{'Complementary mail content'|translate}</strong>
      <br>
      <textarea cols="50" rows="5" name="mail_content" id="mail_content"
        class="description">{if isset($MAIL_CONTENT)}{$MAIL_CONTENT}{/if}</textarea>
    </p>

    {if isset($auth_key_duration)}
      <p>
        {'Each email sent will contain its own automatic authentication key on links, valid for %s.'|translate:$auth_key_duration}
        <br>{'For security reason, authentication keys do not work for administrators.'|translate}
      </p>
    {/if}

    <p class="actionButtons">
      <button name="submitEmail" type="submit" class="buttonLike">
        <i class="icon-mail"></i> {'Send'|translate}
      </button>
      <span class="errors" style="display:none">&#x2718; {'No recipient selected'|translate}</span>
    </p>

  </fieldset>

</form>