{if not $ENABLE_SYNCHRONIZATION}
{html_style}<style>
#helpSynchro { display:none; }
</style>{/html_style}
{/if}

<h2>{'Help'|@translate} &raquo; {$HELP_SECTION_TITLE}</h2>

<div id="helpContent">

{$HELP_CONTENT}

</div>