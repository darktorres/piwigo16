{combine_css path='admin/themes/default/css/pages/help.css' order=-10}
{if not $ENABLE_SYNCHRONIZATION}
    
{/if}

<h2>{'Help'|translate} &raquo; {$HELP_SECTION_TITLE}</h2>

<div id="helpContent">

    {$HELP_CONTENT}

</div>