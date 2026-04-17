<li id="languageSwitch"><a id="languageSwitchLink" title="{'Language'|translate}" class="pwg-state-default pwg-button"
    rel="nofollow">
    <span class="pwg-icon langflag-{$lang_switch.Active.code}">&nbsp;</span><span
      class="pwg-button-text">{'Language'|translate}</span>
  </a>
  <div id="languageSwitchBox" class="switchBox">
    <div class="switchBoxTitle">{'Language'|translate}</div>
    {foreach from=$lang_switch.flags item=flag name=f}
      <a rel="nofollow" href="{$flag.url}">
        {if $lang_info.direction=="ltr"}<span
          class="pwg-icon langflag-{$flag.code}">{$flag.alt}</span>{$flag.title}{else}{$flag.title}<span
          class="pwg-icon langflag-{$flag.code}">{$flag.alt}</span>{/if}
      </a>
      {if ($smarty.foreach.f.index+1)%3 == 0}<br>{/if}
    {/foreach}
  </div>
</li>

{footer_script}<script>
  var _lsLink = document.getElementById("languageSwitchLink");
  var _lsBox  = document.getElementById("languageSwitchBox");
  if (_lsLink && _lsBox) {
    _lsLink.addEventListener("click", function() {
      var r = _lsLink.getBoundingClientRect();
      var linkLeft = r.left + window.scrollX;
      var linkTop  = r.bottom + window.scrollY;
      var boxW = _lsBox.offsetWidth +
        parseInt(getComputedStyle(_lsBox).marginLeft || 0) +
        parseInt(getComputedStyle(_lsBox).marginRight || 0);
      _lsBox.style.left = Math.min(linkLeft, window.innerWidth - boxW - 5) + "px";
      _lsBox.style.top  = linkTop + "px";
      _lsBox.style.display = window.getComputedStyle(_lsBox).display === "none" ? "block" : "none";
    });
    _lsBox.addEventListener("mouseleave", function() {
      _lsBox.style.display = "none";
    });
  }
</script>{/footer_script}

{* <!-- stylish for themes missing .switchBox styles --> *}
{if $LANGUAGE_SWITCH_LOAD_STYLE}
  {combine_css path=$LANGUAGE_SWITCH_PATH|cat:"style.css"}
{/if}

{* <!-- common style specific for LanguageSwitch --> *}
{combine_css path=$LANGUAGE_SWITCH_PATH|cat:"language_switch.css"}