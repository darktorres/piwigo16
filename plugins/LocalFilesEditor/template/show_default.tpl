{combine_script id="codemirror" path="node_modules/codemirror/lib/codemirror.js"}
{combine_script id="codemirror.xml" require="codemirror" path="node_modules/codemirror/mode/xml/xml.js"}
{combine_script id="codemirror.javascript" require="codemirror" path="node_modules/codemirror/mode/javascript/javascript.js"}
{combine_script id="codemirror.css" require="codemirror" path="node_modules/codemirror/mode/css/css.js"}
{combine_script id="codemirror.clike" require="codemirror" path="node_modules/codemirror/mode/clike/clike.js"}
{combine_script id="codemirror.htmlmixed" require="codemirror.xml,codemirror.javascript,codemirror.css" path="node_modules/codemirror/mode/htmlmixed/htmlmixed.js"}
{combine_script id="codemirror.php" require="codemirror.xml,codemirror.javascript,codemirror.css,codemirror.clike" path="node_modules/codemirror/mode/php/php.js"}

{combine_css path="plugins/LocalFilesEditor/template/locfiledit.css"}

{footer_script}<script>
var editor = CodeMirror.fromTextArea(document.getElementById("text"), {
  readOnly: true,
  mode: "application/x-httpd-php"
});
</script>{/footer_script}

{html_head}
<style type="text/css">
#headbranch, #theHeader, #copyright { display: none; }
</style>
{/html_head}

<div id="LocalFilesEditor">

<div id="title_bar">
  <span class="file_name">{$TITLE}</span>
</div>

<textarea id="text" rows="30" cols="90" class="show_default_area">{$DEFAULT_CONTENT}</textarea>

</div>
