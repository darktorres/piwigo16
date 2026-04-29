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

<!-- BEGIN get_combined_scripts -->
{get_combined_scripts load='header'}
<!-- END get_combined_scripts -->

{combine_script id='jquery' path='themes/default/js/jquery.min.js'}
{literal}
<script type="text/javascript">
$(document).ready(function() {
  $("a.externalLink").click(function() {
    window.open($(this).attr("href"));
    return false;
  });

  $("#admin_mail").keyup(function() {
    $(".adminEmail").text($(this).val());
  });
});
</script>

<style type="text/css">
body {
  font-size: 12px;
  background-color: #1b1b1b;
  color: #c1c1c1;
}

#content {
  width: 800px;
  margin: auto;
  text-align: center;
  padding: 0;
  background-color: transparent !important;
  border: none;
}

#theHeader {
  display: block;
  background: url("admin/themes/default/images/piwigo-orange.svg") no-repeat scroll center 20px transparent;
  height: 100px;
  background-size: 300px;
}

fieldset {
  margin-top: 20px;
  background-color: #2c2c2c;
  border: 1px solid #444;
}

legend {
  font-weight: bold;
  letter-spacing: 2px;
  color: #c1c1c1;
}

.content h2 {
  display: block;
  font-size: 20px;
  text-align: center;
  color: #c1c1c1;
}

table.table2 {
  width: 100%;
  border: 0;
}

table.table2 td {
  text-align: left;
  padding: 5px 2px;
  color: #c1c1c1;
}

table.table2 td.fieldname {
  font-weight: normal;
}

table.table2 td.fielddesc {
  padding-left: 10px;
  font-style: italic;
  color: #888;
}

input[type="submit"], input[type="button"], a.bigButton {
  font-size: 14px;
  font-weight: bold;
  letter-spacing: 2px;
  border: none;
  background-color: #ffa646;
  color: #493c21;
  padding: 5px 14px;
  border-radius: 5px;
  cursor: pointer;
}

input[type="submit"]:hover, input[type="button"]:hover, a.bigButton:hover {
  background-color: #ff7700;
  color: #493c21;
}

input[type="text"], input[type="password"], select {
  background-color: #3c3c3c;
  border: 2px solid #555;
  border-radius: 5px;
  padding: 2px 6px;
  color: #c1c1c1;
}

input[type="text"]:focus, input[type="password"]:focus, select:focus {
  background-color: #444;
  border: 2px solid #ff7700;
  outline: none;
}

input[type="checkbox"] {
  accent-color: #ffa646;
}

a {
  color: #ffa646;
}

a:hover {
  color: #ff7700;
}

.sql_content {
  color: #ff3363;
}

.errors {
  padding-bottom: 5px;
  color: #f55;
  background-color: #3a1a1a;
  border-left: 4px solid #f22;
}

.infos {
  color: #7ecf7e;
  background-color: #1a3a1a;
  border-left: 4px solid #0a0;
}

#the_page > div[style*="text-align: center"] {
  color: #888;
}
</style>
{/literal}

{combine_script id='jquery.cluetip' load='async' require='jquery' path='themes/default/js/plugins/jquery.cluetip.js'}

{footer_script require='jquery.cluetip'}
jQuery().ready(function(){ldelim}
	jQuery('.cluetip').cluetip({ldelim}
		width: 300,
		splitTitle: '|',
		positionBy: 'bottomTop'
	});
});
{/footer_script}


<title>Piwigo {$RELEASE} - {'Installation'|@translate}</title>
</head>

<body>
<div id="the_page">
<div id="theHeader"></div>
<div id="content" class="content">

<h2>{'Version'|@translate} {$RELEASE} - {'Installation'|@translate}</h2>

{if isset($config_creation_failed)}
<div class="errors">
  <p style="margin-left:30px;">
    <strong>{'Creation of config file local/config/database.inc.php failed.'|@translate}</strong>
  </p>
  <ul>
    <li>
      <p>{'You can download the config file and upload it to local/config directory of your installation.'|@translate}</p>
      <p style="text-align:center">
          <input type="button" value="{'Download the config file'|@translate}" onClick="window.open('{$config_url}');">
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
      <td style="width: 30%">{'Default gallery language'|@translate}</td>
      <td>
    <select name="language" onchange="document.location = 'install.php?language='+this.options[this.selectedIndex].value;">
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
      <td style="width: 30%;" class="fieldname">{'Host'|@translate}</td>
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
      <td style="width: 30%;" class="fieldname">{'Username'|@translate}</td>
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

  <div style="text-align:center; margin:20px 0 10px 0">
    <input class="submit" type="submit" name="install" value="{'Start Install'|@translate}">
  </div>
</form>
{else}
<p>
  <a class="bigButton" href="index.php">{'Visit Gallery'|@translate}</a>
</p>
{/if}
</div> {* content *}
<div style="text-align: center">{$L_INSTALL_HELP}</div>
</div> {* the_page *}

<!-- BEGIN get_combined_scripts -->
{get_combined_scripts load='footer'}
<!-- END get_combined_scripts -->

</body>
</html>
