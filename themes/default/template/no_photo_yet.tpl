<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="stylesheet" type="text/css" href="themes/default/theme.css">
<title>Piwigo, {'Welcome'|@translate}</title>
{literal}
<style type="text/css">
body {
  margin: 0;
  padding: 0;
  background-color: #1b1b1b;
}

P { text-align: center; }
TD { color: #c1c1c1; letter-spacing: 1px; }

#global {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 700px;
  height: 400px;
  margin-top: -200px;
  margin-left: -350px;
  background-color: #2c2c2c;
  border: 2px solid #444;
}

#noPhotoWelcome { font-size: 25px; color: #c1c1c1; text-align: center; letter-spacing: 1px; margin-top: 30px; }

.bigButton { text-align: center; margin-top: 120px; }

.bigButton a {
  background-color: #ffa646;
  padding: 20px;
  text-decoration: none;
  margin: 0 5px;
  border-radius: 6px;
  color: #493c21;
  font-size: 25px;
  letter-spacing: 2px;
}

.bigButton a:hover {
  background-color: #ff7700;
  outline: none;
  color: #493c21;
  border: none;
}

#deactivate {
  position: absolute;
  bottom: 10px;
  text-align: center;
  width: 100%;
  font-style: normal;
  font-size: 1.0em;
}

.submit { font-size: 1.0em; letter-spacing: 2px; font-weight: normal; }

#deactivate a {
  text-decoration: none;
  border: none;
  color: #ffa646;
}

#deactivate a:hover {
  border-bottom: 1px dotted #ffa646;
}

#quickconnect {
  margin: 0 auto;
  margin-top: 60px;
  width: 300px;
  color: #c1c1c1;
  font-size: 14px;
  letter-spacing: 1px;
}

#quickconnect input[type="text"], #quickconnect input[type="password"] {
  width: 300px;
  color: #c1c1c1;
  font-size: 20px;
  margin-top: 3px;
  background-color: #3c3c3c;
  border: 2px solid #555;
  border-radius: 5px;
  padding: 2px 6px;
  box-sizing: border-box;
}

#quickconnect input[type="text"]:focus, #quickconnect input[type="password"]:focus {
  background-color: #444;
  border: 2px solid #ff7700;
  outline: none;
}

#quickconnect input[type="submit"] {
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

#quickconnect input[type="submit"]:hover {
  background-color: #ff7700;
  color: #493c21;
}
</style>
{/literal}

</head>

<body>
<div id="global">

{if $step == 1}
<p id="noPhotoWelcome">{'Welcome to your Piwigo photo gallery!'|@translate}</p>

<form method="post" action="{$U_LOGIN}" id="quickconnect">
{'Username'|@translate}
<br><input type="text" name="username">
<br>
<br>{'Password'|@translate}
<br><input type="password" name="password">

<p><input class="submit" type="submit" name="login" value="{'Login'|@translate}"></p>

</form>
<div id="deactivate"><a href="{$deactivate_url}">{'... or browse your empty gallery'|@translate}</a></div>


{else}
<p id="noPhotoWelcome">{$intro}</p>
<div class="bigButton"><a href="{$next_step_url}">{'I want to add photos'|@translate}</a></div>
<div id="deactivate"><a href="{$deactivate_url}">{'... or please deactivate this message, I will find my way by myself'|@translate}</a></div>
{/if}


</div>
</body>

</html>

