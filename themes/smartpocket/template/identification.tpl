<div>
  {include file='infos_errors.tpl'}
  <h3>{'Identification'|translate}</h3>
  <form action="{$F_LOGIN_ACTION}" method="post" name="login_form" class="properties">

    <div>
      <label for="username">{'Username'|translate}</label>
      <input type="text" name="username" id="username">
    </div>


    <div>
      <label for="password">{'Password'|translate}</label>
      <input type="password" name="password" id="password" value="">
    </div>

    {if $authorize_remembering }
      <div>
        <label for="remember_me">{'Auto login'|translate}</label>
        <input type="checkbox" name="remember_me" id="remember_me" value="1">
      </div>
    {/if}

    <div>
      <input type="hidden" name="redirect" value="{$U_REDIRECT|urlencode}">
      <input type="submit" name="login" value="{'Submit'|translate}">
    </div>

  </form>

  <div style="margin-top:2em">
    {if isset($U_LOST_PASSWORD)}
      <a href="{$U_LOST_PASSWORD}">{'Forgot your password?'|translate}</a>
    {/if}

    {if isset($U_REGISTER)}
      <a href="{$U_REGISTER}">{'Register'|translate}</a>
    {/if}

  </div>
</div>