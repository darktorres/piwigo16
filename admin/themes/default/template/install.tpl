<!DOCTYPE html>
<html lang="{$lang_info.code}" dir="{$lang_info.direction}">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset={$T_CONTENT_ENCODING}">
  <meta http-equiv="Content-script-type" content="text/javascript">
  <meta http-equiv="Content-Style-Type" content="text/css">
  <link rel="shortcut icon" type="image/x-icon" href="{$ROOT_URL}{$themeconf.icon_dir}/favicon.ico">

  {get_combined_css}
  {foreach $themes as $theme}
    {if $theme.load_css}
      {combine_css path="admin/themes/`$theme.id`/theme.css" order=-10}
    {/if}
  {/foreach}

  <!-- BEGIN get_combined_scripts -->
  {get_combined_scripts load='header'}
  <!-- END get_combined_scripts -->

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll("a.externalLink").forEach(function(el) {
        el.addEventListener('click', function(e) {
          e.preventDefault();
          window.open(this.getAttribute("href"));
        });
      });

      var adminMail = document.getElementById("admin_mail");
      if (adminMail) {
        adminMail.addEventListener('keyup', function() {
          document.querySelectorAll(".adminEmail").forEach(function(el) {
            el.textContent = adminMail.value;
          });
        });
      }
    });
  </script>

  <style>
    body {
      font-size: 12px;
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
      background-color: #f1f1f1;
    }

    legend {
      font-weight: bold;
      letter-spacing: 2px;
    }

    .content h2 {
      display: block;
      font-size: 20px;
      text-align: center;
      /* margin-top:5px; */
    }

    table.table2 {
      width: 100%;
      border: 0;
    }

    table.table2 td {
      text-align: left;
      padding: 5px 2px;
    }

    table.table2 td.fieldname {
      font-weight: normal;
    }

    table.table2 td.fielddesc {
      padding-left: 10px;
      font-style: italic;
    }

    input[type="submit"],
    input[type="button"],
    a.bigButton {
      font-size: 14px;
      font-weight: bold;
      letter-spacing: 2px;
      border: none;
      background-color: #666666;
      color: #fff;
      padding: 5px;
      -moz-border-radius: 5px;
      -webkit-border-radius: 5px;
      border-radius: 5px;
    }

    input[type="submit"]:hover,
    input[type="button"]:hover,
    a.bigButton:hover {
      background-color: #ff7700;
      color: white;
    }

    input[type="text"],
    input[type="password"],
    select {
      background-color: #ddd;
      border: 2px solid #ccc;
      -moz-border-radius: 5px;
      -webkit-border-radius: 5px;
      border-radius: 5px;
      padding: 2px;
    }

    input[type="text"]:focus,
    input[type="password"]:focus,
    select:focus {
      background-color: #fff;
      border: 2px solid #ff7700;
    }

    .sql_content,
    .infos a {
      color: #ff3363;
    }

    .errors {
      padding-bottom: 5px;
    }
  </style>

  {footer_script}<script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.cluetip').forEach(function(el) {
        var raw = el.getAttribute('title') || '';
        el.removeAttribute('title');
        el.dataset.cluetip = raw;
        el.addEventListener('mouseenter', function() {
          var parts = (el.dataset.cluetip || '').split('|');
          var tip = document.createElement('div');
          tip.className = 'pwg-cluetip';
          tip.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;padding:8px;width:300px;box-shadow:2px 2px 6px rgba(0,0,0,.2);font-size:13px;';
          tip.innerHTML = '<strong>' + (parts[0] || '') + '</strong>' + (parts[1] ? '<hr style="margin:4px 0">' + parts[1] : '');
          document.body.appendChild(tip);
          var rect = el.getBoundingClientRect();
          tip.style.left = (rect.left + window.scrollX) + 'px';
          tip.style.top = (rect.bottom + window.scrollY + 4) + 'px';
          el._cluetip = tip;
        });
        el.addEventListener('mouseleave', function() {
          if (el._cluetip) { el._cluetip.remove(); el._cluetip = null; }
        });
      });
    });
  </script>{/footer_script}


  <title>Piwigo {$RELEASE} - {'Installation'|translate}</title>
</head>

<body>
  <div id="the_page">
    <div id="theHeader"></div>
    <div id="content" class="content">

      <h2>{'Version'|translate} {$RELEASE} - {'Installation'|translate}</h2>

      {if isset($config_creation_failed)}
        <div class="errors">
          <p style="margin-left:30px;">
            <strong>{'Creation of config file local/config/database.php failed.'|translate}</strong>
          </p>
          <ul>
            <li>
              <p>
                {'You can download the config file and upload it to local/config directory of your installation.'|translate}
              </p>
              <p style="text-align:center">
                <input type="button" value="{'Download the config file'|translate}"
                  onClick="window.open('{$config_url}');">
              </p>
            </li>
            <li>
              <p>
                {'An alternate solution is to copy the text in the box above and paste it into the file "local/config/database.php" (Warning : database.php must only contain what is in the textarea, no line return or space character)'|translate}
              </p>
              <textarea rows="15" cols="70">{$config_file_content}</textarea>
            </li>
          </ul>
        </div>
      {/if}

      {if isset($errors)}
        <div class="errors">
          <ul>
            {foreach $errors as $error}
              <li>{$error}</li>
            {/foreach}
          </ul>
        </div>
      {/if}

      {if isset($infos)}
        <div class="infos">
          <ul>
            {foreach $infos as $info}
              <li>{$info}</li>
            {/foreach}
          </ul>
        </div>
      {/if}

      {if isset($install)}
        <form method="POST" action="{$F_ACTION}" name="install_form">

          <fieldset>
            <legend>{'Basic configuration'|translate}</legend>

            <table class="table2">
              <tr>
                <td style="width: 30%">{'Default gallery language'|translate}</td>
                <td>
                  <select name="language"
                    onchange="document.location = 'install.php?language='+this.options[this.selectedIndex].value;">
                    {html_options options=$language_options selected=$language_selection}
                  </select>
                </td>
              </tr>
            </table>
          </fieldset>

          <fieldset>
            <legend>{'Database configuration'|translate}</legend>

            <table class="table2">
              <tr>
                <td class="fieldname">{'Database Type'|translate}</td>
                <td>
                  <select id="dbtype" name="dbtype" required>
                    <option value="mysqli">MySQL</option>
                    <option value="mysqli-socket">MySQL Socket</option>
                    <option value="pgsql">PostgreSQL</option>
                    <option value="pgsql-socket">PostgreSQL Socket</option>
                  </select>
                </td>
                <td class="fielddesc">{'Select the type of your database'|translate}</td>
              </tr>
              <tr>
                <td style="width: 30%;" class="fieldname">{'Host:Port'|translate}</td>
                <td><input type="text" id="dbhost" name="dbhost" value="127.0.0.1:3306" required></td>
                <td class="fielddesc">{'localhost or other, supplied by your host provider'|translate}</td>
              </tr>
              <tr>
                <td class="fieldname">{'User'|translate}</td>
                <td><input type="text" id="dbuser" name="dbuser" value="root" required autocomplete="username"></td>
                <td class="fielddesc">{'user login given by your host provider'|translate}</td>
              </tr>
              <tr>
                <td class="fieldname">{'Password'|translate}</td>
                <td><input type="password" id="dbpasswd" name="dbpasswd" value="1234" autocomplete="current-password"></td>
                <td class="fielddesc">{'user password given by your host provider'|translate}</td>
              </tr>
              <tr>
                <td class="fieldname">{'Database name'|translate}</td>
                <td><input type="text" name="dbname" value="piwigo_fork" required></td>
                <td class="fielddesc">{'also given by your host provider'|translate}</td>
              </tr>
            </table>

            <script>
              document.addEventListener('DOMContentLoaded', function() {
                const dbtypeElement = document.getElementById('dbtype');

                if (dbtypeElement) {
                  dbtypeElement.addEventListener('change', function() {
                    const dbType = this.value;
                    const dbHostInput = document.getElementById('dbhost');
                    const dbUserInput = document.getElementById('dbuser');
                    const dbPasswdInput = document.getElementById('dbpasswd');

                    switch (dbType) {
                      case 'mysqli':
                        dbHostInput.value = '127.0.0.1:3306';
                        dbUserInput.value = 'root';
                        dbPasswdInput.value = '1234';
                        break;
                      case 'mysqli-socket':
                        dbHostInput.value = '/var/run/mysqld/mysqld.sock';
                        dbUserInput.value = 'www-data';
                        dbPasswdInput.value = '';
                        break;
                      case 'pgsql':
                        dbHostInput.value = 'localhost:5432';
                        dbUserInput.value = 'postgres';
                        dbPasswdInput.value = '1234';
                        break;
                      case 'pgsql-socket':
                        dbHostInput.value = '/var/run/postgresql';
                        dbUserInput.value = 'www-data';
                        dbPasswdInput.value = '';
                        break;
                    }
                  });
                }
              });
            </script>

          </fieldset>
          <fieldset>
            <legend>{'Admin configuration'|translate}</legend>

            <table class="table2">
              <tr>
                <td style="width: 30%;" class="fieldname">{'Username'|translate}</td>
                <td><input type="text" name="admin_name" value="darktorres" required autocomplete="username"></td>
                <td class="fielddesc">
                  {'It will be shown to the visitors. It is necessary for website administration'|translate}</td>
              </tr>
              <tr>
                <td class="fieldname">{'Password'|translate}</td>
                <td><input type="password" name="admin_pass1" value="1234" required autocomplete="new-password"></td>
                <td class="fielddesc">{'Keep it confidential, it enables you to access administration panel'|translate}
                </td>
              </tr>
              <tr>
                <td class="fieldname">{'Password [confirm]'|translate}</td>
                <td><input type="password" name="admin_pass2" value="1234" required autocomplete="new-password"></td>
                <td class="fielddesc">{'verification'|translate}</td>
              </tr>
              <tr>
                <td class="fieldname">{'Email address'|translate}</td>
                <td><input type="text" name="admin_mail" id="admin_mail" value="torres.dark@gmail.com" required
                    autocomplete="email"></td>
                <td class="fielddesc">{'Visitors will be able to contact site administrator with this mail'|translate}
                </td>
              </tr>
              <tr>
                <td>{'Options'|translate}</options>
                <td colspan="2">
                  <label>
                    <input type="checkbox" name="newsletter_subscribe" {if $F_NEWSLETTER_SUBSCRIBE}{/if}>
                    <span class="cluetip"
                      title="{'Piwigo Announcements Newsletter'|translate}|{'Keep in touch with Piwigo project, subscribe to Piwigo Announcement Newsletter. You will receive emails when a new release is available (sometimes including a security bug fix, it\'s important to know and upgrade) and when major events happen to the project. Only a few emails a year.'|translate|htmlspecialchars|nl2br}">{'Subscribe %s to Piwigo Announcements Newsletter'|translate:$EMAIL}</span>
                  </label>
                  <br>
                  <label>
                    <input type="checkbox" name="send_credentials_by_mail">
                    {'Send my connection settings by email'|translate}
                  </label>
                </td>
              </tr>
            </table>

          </fieldset>

          <div style="text-align:center; margin:20px 0 10px 0">
            <input class="submit" type="submit" name="install" value="{'Start Install'|translate}">
          </div>
        </form>
      {else}
        <p>
          <a class="bigButton" href="index.php">{'Visit Gallery'|translate}</a>
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