{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='pwgConfirm' load='footer' path='admin/themes/default/js/pwgConfirm.js'}
{footer_script require='pwgConfirm'}<script>
  document.querySelectorAll(".delete-lang-button").forEach(function(el) {
    var title_msg = '{'Are you sure you want to delete the language "%s"?'|translate|escape:'javascript'}';
    var confirm_msg = '{"Yes, I am sure"|translate}';
    var cancel_msg = '{"No, I have changed my mind"|translate|escape:'javascript'}';
    var langBox = el.closest(".languageBox");
    var langNameEl = langBox ? langBox.querySelector('.languageName') : null;
    var lang_name = langNameEl ? langNameEl.innerHTML : '';
    pwgConfirmFollowHref(el, {
      alert_title: title_msg.replace("%s", lang_name),
      alert_confirm: confirm_msg,
      alert_cancel: cancel_msg
    });
  });
</script>{/footer_script}

{foreach $language_states as $language_state}
  <fieldset>
    <legend>
      {if $language_state == 'active'}
        {'Active Languages'|translate}

      {elseif $language_state == 'inactive'}
        {'Inactive Languages'|translate}

      {/if}
    </legend>
    <div class="languageBoxes">
      {foreach $languages as $language}
        {if $language.state == $language_state}
          <div class="languageBox{if $language.is_default} languageDefault{/if}">
            <div class="languageName">{$language.name}{if $language.is_default} <em>({'default'|translate})</em>{/if}</div>
            {if $isWebmaster == 1}
              <div class="languageActions">
                <div>
                  {if $language_state == 'active'}
                    {if $language.deactivatable}
                      <a href="{$language.u_action}&amp;action=deactivate" class="tiptip"
                        title="{'Forbid this language to users'|translate}">{'Deactivate'|translate}</a>
                    {else}
                      <span title="{$language.deactivate_tooltip}">{'Deactivate'|translate}</span>
                    {/if}

                    {if not $language.is_default}
                      | <a href="{$language.u_action}&amp;action=set_default" class="tiptip"
                        title="{'Set as default language for unregistered and new users'|translate}">{'Default'|translate}</a>
                    {/if}
                  {/if}

                  {if $language_state == 'inactive'}
                    <a href="{$language.u_action}&amp;action=activate" class="tiptip"
                      title="{'Make this language available to users'|translate}">{'Activate'|translate}</a>
                    {if $CONF_ENABLE_EXTENSIONS_INSTALL}
                      | <a href="{$language.u_action}&amp;action=delete" class="tiptip delete-lang-button"
                        title="{'Delete this language'|translate}">{'Delete'|translate}</a>
                    {/if}
                  {/if}
                </div>
              </div> <!-- languageActions -->
            {/if}
          </div> <!-- languageBox -->
        {/if}
      {/foreach}
    </div> <!-- languageBoxes -->
  </fieldset>
{/foreach}