{if !$table_exists}
<div class="infos">
  <ul><li>{'The job queue table does not exist yet. Dispatch your first job to initialise it.'|translate}</li></ul>
</div>
{else}

<fieldset>
  <legend><span class="icon-layers icon-blue"></span>{'Queue Status'|translate}</legend>
  <table class="table2" style="width:auto">
    <thead>
      <tr>
        <th>{'Queue'|translate}</th>
        <th>{'Pending Jobs'|translate}</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>async</td>
        <td>{$pending_async}</td>
      </tr>
      <tr>
        <td>failed</td>
        <td>{$pending_failed}</td>
      </tr>
    </tbody>
  </table>
</fieldset>

<fieldset>
  <legend><span class="icon-cog icon-green"></span>{'Worker Command'|translate}</legend>
  <p>{'Run the following command to process queued jobs:'|translate}</p>
  <pre style="background:#f5f5f5;padding:8px;border-radius:4px">{$worker_command}</pre>
</fieldset>

{if $pending_failed gt 0}
<fieldset>
  <legend><span class="icon-attention icon-red"></span>{'Failed Jobs'|translate} ({$pending_failed})</legend>
  <table class="table2">
    <thead>
      <tr>
        <th>ID</th>
        <th>{'Job Type'|translate}</th>
        <th>{'Created'|translate}</th>
        <th>{'Actions'|translate}</th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$failed_jobs item=job}
      <tr>
        <td>{$job.id}</td>
        <td>{$job.class}</td>
        <td>{$job.created_at}</td>
        <td><a href="{$job.U_RETRY}" class="icon-cw">{'Retry'|translate}</a></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <p style="margin-top:8px">
    <a href="{$U_PURGE_FAILED}" class="icon-trash-1" data-confirm='{"\"Are you sure you want to delete all failed jobs?\""|translate}'>
      {'Purge Failed Queue'|translate}
    </a>
  </p>
</fieldset>
{/if}

{/if}
