{combine_css path="themes/admin/default/css/pages/help.css"}

<h2>{'Help'|@translate} &raquo; {$HELP_SECTION_TITLE}</h2>

<div id="helpContent"{if not $ENABLE_SYNCHRONIZATION} class="sync-disabled"{/if}>

{$HELP_CONTENT}

</div>
