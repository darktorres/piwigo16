<!DOCTYPE html>
<html lang="{$lang_info.code}" dir="{$lang_info.direction}">
<head>
<meta http-equiv="Content-Type" content="text/html; charset={$T_CONTENT_ENCODING}">
<meta http-equiv="Content-script-type" content="text/javascript">
<meta http-equiv="Content-Style-Type" content="text/css">
<link rel="shortcut icon" type="image/x-icon" href="{$ROOT_URL}{$themeconf.icon_dir}/favicon.ico">

{get_combined_css}
{foreach from=$themes item=theme}
{if $theme.load_css}
{combine_css path="admin/themes/`$theme.id`/theme.css" order=-10}
{/if}
{/foreach}

<!--[if IE 7]>
  <link rel="stylesheet" type="text/css" href="{$ROOT_URL}admin/themes/default/fix-ie7.css">
<![endif]-->

{combine_script id='install' load='footer' path='admin/themes/default/js/install.js'}

<!-- BEGIN get_combined_scripts -->
{get_combined_scripts load='header'}
<!-- END get_combined_scripts -->

{combine_css path="admin/themes/default/css/pages/install-upgrade.css"}



<title>Piwigo {$RELEASE} - {'Installation'|@translate}</title>
</head>

<body>
<div id="the_page">
<div id="theHeader"></div>
<div id="content" class="content">

<h2>{'Version'|@translate} {$RELEASE} - {'Installation'|@translate}</h2>

{if isset($config_creation_failed)}
<div class="errors">
  <p class="install-config-failed-message">
    <strong>{'Creation of config file local/config/database.inc.php failed.'|@translate}</strong>
  </p>
  <ul>
    <li>
      <p>{'You can download the config file and upload it to local/config directory of your installation.'|@translate}</p>
      <p class="u-text-center">
          <input type="button" value="{'Download the config file'|@translate}" data-install-download-config="{$config_url}">
      </p>
    </li>
    <li>
      <p>{'An alternate solution is to copy the text in the box above and paste it into the file "local/config/database.inc.php" (Warning : database.inc.php must only contain what is in the textarea, no line return or space character)'|@translate}</p>
      <textarea rows="15" cols="70">{$config_file_content}</textarea>
    </li>
  </ul>
</div>
{/if}

{if isset($errors)}
<div class="errors">
  <ul>
    {foreach from=$errors item=error}
    <li>{$error}</li>
    {/foreach}
  </ul>
</div>
{/if}

{if isset($infos)}
<div class="infos">
  <ul>
    {foreach from=$infos item=info}
    <li>{$info}</li>
    {/foreach}
  </ul>
</div>
{/if}

{if isset($install)}
<form method="POST" action="{$F_ACTION}" name="install_form">

<fieldset>
  <legend>{'Basic configuration'|@translate}</legend>

  <table class="table2">
    <tr>
      <td class="u-w-30p">{'Default gallery language'|@translate}</td>
      <td>
    <select name="language" data-language-select-redirect="install.php">
    {html_options options=$language_options selected=$language_selection}
    </select>
      </td>
    </tr>
  </table>
</fieldset>

<fieldset>
  <legend>{'Database configuration'|@translate}</legend>

  <table class="table2">
    <tr>
      <td class="fieldname u-w-30p">{'Host'|@translate}</td>
      <td><input type="text" name="dbhost" value="{$F_DB_HOST}" required></td>
      <td class="fielddesc">{'localhost or other, supplied by your host provider'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'User'|@translate}</td>
      <td><input type="text" name="dbuser" value="{$F_DB_USER}" required></td>
      <td class="fielddesc">{'user login given by your host provider'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Password'|@translate}</td>
      <td><input type="password" name="dbpasswd" value="{$F_DB_PASSWD}"></td>
      <td class="fielddesc">{'user password given by your host provider'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Database name'|@translate}</td>
      <td><input type="text" name="dbname" value="{$F_DB_NAME}" required></td>
      <td class="fielddesc">{'also given by your host provider'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Database table prefix'|@translate}</td>
      <td><input type="text" name="prefix" value="{$F_DB_PREFIX}"></td>
      <td class="fielddesc">{'database tables names will be prefixed with it (enables you to manage better your tables)'|@translate}</td>
    </tr>
  </table>

</fieldset>
<fieldset>
  <legend>{'Admin configuration'|@translate}</legend>

  <table class="table2">
    <tr>
      <td class="fieldname u-w-30p">{'Username'|@translate}</td>
      <td><input type="text" name="admin_name" value="{$F_ADMIN}" required></td>
      <td class="fielddesc">{'It will be shown to the visitors. It is necessary for website administration'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Password'|@translate}</td>
      <td><input type="password" name="admin_pass1" value="{$F_ADMIN_PASS}" required></td>
      <td class="fielddesc">{'Keep it confidential, it enables you to access administration panel'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Password [confirm]'|@translate}</td>
      <td><input type="password" name="admin_pass2" value="{$F_ADMIN_PASS}" required></td>
      <td class="fielddesc">{'verification'|@translate}</td>
    </tr>
    <tr>
      <td class="fieldname">{'Email address'|@translate}</td>
      <td><input type="text" name="admin_mail" id="admin_mail" value="{$F_ADMIN_EMAIL}" required></td>
      <td class="fielddesc">{'Visitors will be able to contact site administrator with this mail'|@translate}</td>
    </tr>
    <tr>
      <td>{'Options'|@translate}</options>
      <td colspan="2">
        <label>
          <input type="checkbox" name="newsletter_subscribe"{if $F_NEWSLETTER_SUBSCRIBE} checked="checked"{/if}>
          <span class="cluetip" title="{'Piwigo Announcements Newsletter'|@translate}|{'Keep in touch with Piwigo project, subscribe to Piwigo Announcement Newsletter. You will receive emails when a new release is available (sometimes including a security bug fix, it\'s important to know and upgrade) and when major events happen to the project. Only a few emails a year.'|@translate|@htmlspecialchars|@nl2br}">{'Subscribe %s to Piwigo Announcements Newsletter'|@translate:$EMAIL}</span>
        </label>
        <br>
        <label>
          <input type="checkbox" name="send_credentials_by_mail">
          {'Send my connection settings by email'|@translate}
        </label>
      </td>
    </tr>
  </table>

</fieldset>

  <div class="install-submit-row">
    <input class="submit" type="submit" name="install" value="{'Start Install'|@translate}">
  </div>
</form>
{else}
<p>
  <a class="bigButton" href="index.php">{'Visit Gallery'|@translate}</a>
</p>
{/if}
</div> {* content *}
<div class="install-help-text">{$L_INSTALL_HELP}</div>
</div> {* the_page *}

<!-- BEGIN get_combined_scripts -->
{get_combined_scripts load='footer'}
<!-- END get_combined_scripts -->

</body>
</html>
