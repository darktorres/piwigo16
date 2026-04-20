{combine_css path='themes/default/css/no_photo_yet.css' order=-10}
<!DOCTYPE html>
<html>

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <link rel="stylesheet" href="themes/default/theme.css">
  <title>Piwigo, {'Welcome'|translate}</title>
  

</head>

<body>
  <div id="global">

    {if $step == 1}
      <p id="noPhotoWelcome">{'Welcome to your Piwigo photo gallery!'|translate}</p>

      <form method="post" action="{$U_LOGIN}" id="quickconnect">
        {'Username'|translate}
        <br><input type="text" name="username" autocomplete="username">
        <br>
        <br>{'Password'|translate}
        <br><input type="password" name="password" autocomplete="current-password">

        <p><input class="submit" type="submit" name="login" value="{'Login'|translate}"></p>

      </form>
      <div id="deactivate"><a href="{$deactivate_url}">{'... or browse your empty gallery'|translate}</a></div>

    {else}
      <p id="noPhotoWelcome">{$intro}</p>
      <div class="bigButton"><a href="{$next_step_url}">{'I want to add photos'|translate}</a></div>
      <div id="deactivate"><a
          href="{$deactivate_url}">{'... or please deactivate this message, I will find my way by myself'|translate}</a>
      </div>
    {/if}

  </div>
</body>

</html>