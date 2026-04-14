{footer_script}<script>
(function() {
  // --- File options toggle ---
  $('#syncFiles label').click(function() {
    if ($("input[value='files']:checked").val()) {
      $("input[value='files']").closest("li").find("ul").show();
    } else {
      $("input[value='files']").closest("li").find("ul").hide();
    }
  });

  // --- Real-time sync via Server-Sent Events ---
  var $form = $('#update');
  if (!$form.length) return;

  var $progress = $('#syncProgress');
  var $phases = $('#syncPhases');
  var startTime, timerInterval;
  var activeSubstepId = null, activeSubstepStart = 0;

  var phaseLabels = {};
  phaseLabels.dirs = 'Scanning directories';
  phaseLabels.files = 'Scanning and syncing files';
  phaseLabels.meta = 'Syncing metadata';

  var abortController, paused, resumeResolve, aborted, syncReader;
  var syncRunning = false, syncComplete = false;

  window.addEventListener('beforeunload', function(e) {
    if (syncRunning) {
      e.preventDefault();
    }
  });

  $form.on('submit', function(e) {
    var syncVal = $("input[name='sync']:checked").val();
    var syncMeta = $("input[name='sync_meta']").is(':checked');
    if (!syncVal && !syncMeta) return true;

    e.preventDefault();
    syncRunning = true;
    $form.hide();
    $progress.show();
    $('#syncControls').show();
    startTime = performance.now();
    timerInterval = setInterval(updateElapsed, 100);
    paused = false;
    aborted = false;
    resumeResolve = null;

    abortController = new AbortController();
    var url = new URL(window.location.href);
    url.searchParams.set('sse', '1');

    var formData = new FormData($form[0]);
    formData.append('submit', 'Synchronize');

    fetch(url.toString(), {
      method: 'POST',
      body: formData,
      signal: abortController.signal
    }).then(function(response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      syncReader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      function pump() {
        if (paused) {
          return new Promise(function(resolve) {
            resumeResolve = resolve;
          }).then(pump);
        }
        return syncReader.read().then(function(result) {
          if (result.done) {
            if (!syncComplete) {
              onError('Server process ended unexpectedly. Check PHP error log for details.');
            }
            return;
          }
          buffer += decoder.decode(result.value, { stream: true });
          var parts = buffer.split('\n\n');
          buffer = parts.pop();
          parts.forEach(parseEvent);
          return pump();
        });
      }
      return pump();
    }).catch(function(err) {
      if (aborted) return;
      // Connection may drop during long DB operations (browser TCP timeout).
      // Don't kill the UI — the sync continues server-side.
      // Show a warning but keep timers running.
      $('#syncTitle').text('Synchronization running (connection lost, waiting for server\u2026)');
    });
  });

  $('#syncPause').click(function() {
    if (paused) {
      paused = false;
      $(this).text('Pause');
      $('.syncPausedLabel').remove();
      if (resumeResolve) {
        var fn = resumeResolve;
        resumeResolve = null;
        fn();
      }
    } else {
      paused = true;
      $(this).text('Resume');
      $('#syncElapsed').after('<span class="syncPausedLabel">PAUSED</span>');
    }
  });

  $('#syncAbort').click(function() {
    aborted = true;
    syncRunning = false;
    if (paused && resumeResolve) {
      paused = false;
      var fn = resumeResolve;
      resumeResolve = null;
      fn();
    }
    abortController.abort();
    clearInterval(timerInterval);
    updateElapsed();
    hideControls();
    $('#syncTitle').text('Synchronization aborted');
    $('.sync-phase.running').removeClass('running').addClass('aborted');
    $('.sync-phase.aborted .phase-status').html('\u2717');
    $('#syncResults').html(
      '<p>The synchronization was aborted. Any changes already committed to the database will remain.</p>'
      + '<p class="bottomButtons">'
      + '<button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">'
      + 'Back to sync</button></p>'
    ).show();
  });

  function hideControls() {
    $('#syncControls').hide();
  }

  function parseEvent(raw) {
    if (!raw.trim()) return;
    var lines = raw.trim().split('\n');
    var name = '', data = '';
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].indexOf('event: ') === 0) name = lines[i].substring(7);
      else if (lines[i].indexOf('data: ') === 0) data = lines[i].substring(6);
    }
    if (!name || !data) return;
    try {
      handleEvent(name, JSON.parse(data));
    } catch(ex) {
      console.error('SSE parse error', ex, data);
    }
  }

  function handleEvent(event, data) {
    if (event === 'phase_start') onPhaseStart(data);
    else if (event === 'phase_progress') onPhaseProgress(data);
    else if (event === 'phase_complete') onPhaseComplete(data);
    else if (event === 'substep_start') onSubstepStart(data);
    else if (event === 'substep_progress') onSubstepProgress(data);
    else if (event === 'substep_complete') onSubstepComplete(data);
    else if (event === 'complete') onComplete(data);
    else if (event === 'error') onError(data.message);
  }

  function onPhaseStart(data) {
    var label = phaseLabels[data.phase] || data.phase;
    var h = '<div class="sync-phase running" id="phase-' + data.phase + '">'
      + '<span class="phase-status"><span class="spinner"></span></span>'
      + '<span class="phase-label">' + label + '</span>'
      + '<span class="phase-detail"></span>'
      + '<span class="phase-time"></span>'
      + '</div>';
    $phases.append(h);
  }

  function onSubstepStart(data) {
    var id = 'substep-' + data.phase + '-' + data.id;
    activeSubstepId = id;
    activeSubstepStart = performance.now();
    var h = '<div class="sync-substep running" id="' + id + '">'
      + '<span class="substep-status"><span class="spinner"></span></span>'
      + '<span class="substep-label">' + data.label + '</span>'
      + '<span class="substep-detail"></span>'
      + '<span class="substep-time"></span>';
    if (data.has_progress) {
      h += '<div class="sync-progress-bar">'
        + '<div class="progress-track"><div class="progress-fill"></div></div>'
        + '<span class="progress-text"></span></div>';
    }
    h += '</div>';
    $phases.append(h);
  }

  function onSubstepProgress(data) {
    var $el = $('#substep-' + data.phase + '-' + data.id);
    if (data.detail) $el.find('.substep-detail').text(data.detail);
  }

  function onSubstepComplete(data) {
    var id = 'substep-' + data.phase + '-' + data.id;
    var $el = $('#' + id);
    $el.removeClass('running').addClass('done');
    $el.find('.substep-status').html('\u2713');
    if (data.detail) $el.find('.substep-detail').text(data.detail);
    if (data.elapsed !== undefined) $el.find('.substep-time').text(data.elapsed + 's');
    if (activeSubstepId === id) activeSubstepId = null;
  }

  function onPhaseProgress(data) {
    var $phase = $('#phase-' + data.phase);
    if (data.phase === 'dirs' && data.dir) {
      $phase.find('.phase-detail').text(data.dir);
    } else if (data.phase === 'meta' && data.current !== undefined) {
      var $sub = $('#substep-meta-extract');
      var pct = data.total > 0 ? Math.round((data.current / data.total) * 100) : 0;
      $sub.find('.progress-fill').css('width', pct + '%');
      var infoText = fmt(data.current) + ' / ' + fmt(data.total);
      if (data.file) infoText += ' \u2014 ' + data.file;
      $sub.find('.progress-text').text(infoText);
      $sub.find('.substep-detail').text(
        pct + '% \u2014 ' + data.updated + ' updated, ' + fmt(data.skipped) + ' skipped'
      );
    }
  }

  function onPhaseComplete(data) {
    var $phase = $('#phase-' + data.phase);
    $phase.removeClass('running').addClass('done');
    $phase.find('.phase-status').html('\u2713');
    $phase.find('.phase-time').text(data.elapsed + 's');

    if (data.phase === 'dirs') {
      var p = [];
      if (data.new > 0) p.push(data.new + ' new');
      if (data.deleted > 0) p.push(data.deleted + ' deleted');
      $phase.find('.phase-detail').text(p.length ? p.join(', ') : 'no changes');
    } else if (data.phase === 'files') {
      var p = [];
      if (data.new > 0) p.push(data.new + ' new');
      if (data.deleted > 0) p.push(data.deleted + ' deleted');
      $phase.find('.phase-detail').text(p.length ? p.join(', ') : 'no changes');
    } else if (data.phase === 'meta') {
      $phase.find('.phase-detail').text(
        data.updated + ' updated, ' + fmt(data.skipped) + ' skipped'
      );
    }
  }

  function onComplete(data) {
    syncComplete = true;
    syncRunning = false;
    clearInterval(timerInterval);
    updateElapsed();
    hideControls();
    var title = data.simulate
      ? '[Simulation] Synchronization complete'
      : 'Synchronization complete';
    $('#syncTitle').text(title);

    var h = '';
    if (data.update) {
      h += '<h4>File synchronization</h4><ul>'
        + '<li>' + data.update.new_categories + ' albums added</li>'
        + '<li>' + data.update.new_elements + ' photos added</li>'
        + '<li>' + data.update.del_categories + ' albums deleted</li>'
        + '<li>' + data.update.del_elements + ' photos deleted</li>';
      if (data.update.errors > 0)
        h += '<li style="color:#dc3232">' + data.update.errors + ' errors</li>';
      h += '</ul>';
    }
    if (data.metadata) {
      h += '<h4>Metadata synchronization</h4><ul>'
        + '<li>' + data.metadata.updated + ' photos updated</li>'
        + '<li>' + data.metadata.candidates + ' candidates</li>';
      if (data.metadata.errors > 0)
        h += '<li style="color:#dc3232">' + data.metadata.errors + ' errors</li>';
      h += '</ul>';
    }
    h += '<p class="bottomButtons">'
      + '<button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">'
      + 'Run again</button></p>';
    $('#syncResults').html(h).show();
  }

  function onError(msg) {
    syncRunning = false;
    clearInterval(timerInterval);
    $('#syncTitle').text('Synchronization failed');
    $('#syncResults').html(
      '<div class="errors"><ul><li>' + msg + '</li></ul></div>'
      + '<p class="bottomButtons">'
      + '<button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">'
      + 'Try again</button></p>'
    ).show();
  }

  function updateElapsed() {
    var now = performance.now();
    $('#syncElapsed').text(fmtTime((now - startTime) / 1000));
    if (activeSubstepId) {
      $('#' + activeSubstepId).find('.substep-time').text(
        fmtTime((now - activeSubstepStart) / 1000)
      );
    }
  }

  function fmtTime(s) {
    return s < 60
      ? s.toFixed(1) + 's'
      : Math.floor(s / 60) + 'm ' + Math.floor(s % 60) + 's';
  }

  // Resume timer accurately after tab switch (browsers throttle background timers)
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden && startTime && timerInterval) {
      updateElapsed();
    }
  });

  function fmt(n) {
    return Number(n).toLocaleString();
  }
})();
</script>{/footer_script}

<style>
.sync-phase {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  padding: 10px 0;
  gap: 8px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sync-phase:last-child { border-bottom: none; }
.sync-phase .phase-status {
  width: 22px;
  text-align: center;
  flex-shrink: 0;
  color: #6bc46d;
  font-weight: bold;
  font-size: 16px;
}
.sync-phase.running .phase-label { font-weight: 600; color: #fff; }
.sync-phase .phase-detail { color: #aaa; font-size: 13px; }
.sync-phase .phase-time { margin-left: auto; color: #999; font-size: 12px; }
.sync-progress-bar {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 2px;
  padding-left: 30px;
}
.progress-track {
  flex: 1;
  height: 16px;
  background: #4a4a4a;
  border-radius: 8px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #0073aa, #00a0d2);
  border-radius: 8px;
  transition: width 0.3s ease;
  width: 0;
}
.progress-text { font-size: 12px; color: #bbb; white-space: nowrap; }
.sync-elapsed { margin-top: 12px; color: #aaa; font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid #555;
  border-top-color: #00a0d2;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
.sync-substep {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  padding: 5px 0;
  padding-left: 32px;
  gap: 8px;
  font-size: 13px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}
.sync-substep:last-child { border-bottom: none; }
.sync-substep .substep-status {
  width: 18px;
  text-align: center;
  flex-shrink: 0;
  color: #6bc46d;
  font-weight: bold;
  font-size: 13px;
}
.sync-substep .substep-status .spinner { width: 12px; height: 12px; }
.sync-substep.running .substep-label { color: #ddd; }
.sync-substep .substep-label { color: #999; }
.sync-substep .substep-detail { color: #888; font-size: 12px; }
.sync-substep .substep-time { margin-left: auto; color: #777; font-size: 11px; }
.sync-substep .sync-progress-bar { padding-left: 26px; }
.sync-substep.aborted .substep-status { color: #e25b5b; }
#syncResults h4 { margin: 15px 0 5px; }
#syncResults ul { margin: 0 0 5px 20px; }
#syncControls { margin-left: 20px; }
.syncControlBtn {
  padding: 4px 14px;
  font-size: 12px;
  cursor: pointer;
  margin-right: 6px;
  border: none;
  border-radius: 3px;
}
.syncAbortBtn {
  background: #b32d2e;
  color: #fff;
}
.syncAbortBtn:hover { background: #9b2324; }
.syncPausedLabel {
  color: #f0c33c;
  font-weight: 600;
  margin-left: 10px;
}
.sync-phase.aborted .phase-status { color: #e25b5b; }
</style>

<div class="selectedAlbum site-url-path">
  <span class="icon-folder-open selectedAlbum-first">{$SITE_URL}</span>
</div>

<div id="syncProgress" style="display:none">
  <fieldset>
    <legend><span class="icon-exchange icon-blue"></span> <span id="syncTitle">Synchronization in progress&hellip;</span></legend>
    <div id="syncPhases"></div>
    <p class="sync-elapsed">
      Elapsed: <strong id="syncElapsed">0.0s</strong>
      <span id="syncControls">
        <button type="button" id="syncPause" class="buttonGradient syncControlBtn">Pause</button>
        <button type="button" id="syncAbort" class="buttonGradient syncControlBtn syncAbortBtn">Abort</button>
      </span>
    </p>
  </fieldset>
  <div id="syncResults" style="display:none"></div>
</div>

{if isset($update_result)}
  <h3>{$L_RESULT_UPDATE}</h3>
  <ul>
    <li class="update_summary_new">{$update_result.NB_NEW_CATEGORIES} {'albums added in the database'|translate}</li>
    <li class="update_summary_new">{$update_result.NB_NEW_ELEMENTS} {'photos added in the database'|translate}</li>
    <li class="update_summary_del">{$update_result.NB_DEL_CATEGORIES} {'albums deleted in the database'|translate}</li>
    <li class="update_summary_del">{$update_result.NB_DEL_ELEMENTS} {'photos deleted from the database'|translate}</li>
    <li>{$update_result.NB_UPD_ELEMENTS} {'photos updated in the database'|translate}</li>
    <li class="update_summary_err">{$update_result.NB_ERRORS} {'errors during synchronization'|translate}</li>
  </ul>
{/if}

{if isset($metadata_result)}
  <h3>{$L_RESULT_METADATA}</h3>
  <ul>
    <li>{$metadata_result.NB_ELEMENTS_DONE} {'photos information synchronized with files metadata'|translate}</li>
    <li>{$metadata_result.NB_ELEMENTS_CANDIDATES} {'photos candidates for metadata synchronization'|translate}</li>
    <li>{'Used metadata'|translate} : {$METADATA_LIST}</li>
  </ul>
{/if}


{if not empty($sync_errors)}
  <h3>{'Error list'|translate}</h3>
  <div class="errors">
    <ul>
      {foreach $sync_errors as $error}
        <li>[{$error.ELEMENT}] {$error.LABEL}</li>
      {/foreach}
    </ul>
  </div>
  <h3>{'Errors caption'|translate}</h3>
  <ul>
    {foreach $sync_error_captions as $caption}
      <li><strong>{$caption.TYPE}</strong>: {$caption.LABEL}</li>
    {/foreach}
  </ul>
{/if}

{if not empty($sync_infos)}
  <h3>{'Detailed information'|translate}</h3>
  <div class="infos">
    <ul>
      {foreach $sync_infos as $info}
        <li>[{$info.ELEMENT}] {$info.LABEL}</li>
      {/foreach}
    </ul>
  </div>
{/if}

{if isset($introduction)}
  <form action="" method="post" id="update">

    <fieldset id="syncFiles">
      <legend><span class="icon-docs icon-blue"></span>{'synchronize files structure with database'|translate}</legend>
      <ul>
        <li><label><input type="radio" name="sync" value="" {if empty($introduction.sync)}checked="checked" {/if}>
            {'nothing'|translate}</label></li>
        <li><label><input type="radio" name="sync" value="dirs" {if 'dirs'==$introduction.sync}checked="checked" {/if}>
            {'only directories'|translate}</label></li>

        <li><label><input type="radio" name="sync" value="files" {if 'files'==$introduction.sync}checked="checked" {/if}>
            {'directories + files'|translate}</label>
          <ul style="display:none;padding-left:3em">
            <li><label><input type="checkbox" name="display_info" value="1"
                  {if $introduction.display_info}checked="checked" {/if}>
                {'display maximum information (added albums and photos, deleted albums and photos)'|translate}</label>
            </li>
            <li><label><input type="checkbox" name="add_to_caddie" value="1"
                  {if $introduction.add_to_caddie}checked="checked" {/if}> {'add new photos to caddie'|translate}</label>
            </li>
            <li><label>{'Who can see these photos?'|translate} <select
                  name="privacy_level">{html_options options=$introduction.privacy_level_options selected=$introduction.privacy_level_selected}</select></label>
            </li>
          </ul>
        </li>
      </ul>
    </fieldset>

    <fieldset id="syncMetadata">
      <legend><span
          class="icon-hdd icon-red"></span>{'synchronize files metadata with database photos information'|translate}
      </legend>
      <label><input type="checkbox" name="sync_meta" {if $introduction.sync_meta}checked="checked" {/if}>
        {'Synchronize metadata'|translate} ({$METADATA_LIST})</label>
      <ul style="padding-left:3em">
        <li>
          <label><input type="checkbox" name="meta_all" {if $introduction.meta_all}checked="checked" {/if}>
            {'even already synchronized photos'|translate}</label>
        </li>
        <li>
          <label><input type="checkbox" name="meta_empty_overrides"
              {if $introduction.meta_empty_overrides}checked="checked" {/if}>
            {'overrides existing values with empty ones'|translate}</label>
        </li>
      </ul>
    </fieldset>

    <fieldset id="syncSimulate">
      <legend><span class="icon-chart-bar icon-green"></span>{'Simulation'|translate}</legend>
      <ul>
        <li><label><input type="checkbox" name="simulate" value="1" checked="checked">
            {'only perform a simulation (no change in database will be made)'|translate}</label></li>
      </ul>
    </fieldset>

    <fieldset id="catSubset">
      <legend><span class="icon-filter icon-purple"></span>{'reduce to single existing albums'|translate}</legend>
      <ul>
        <li>
          <select class="categoryList" name="cat" size="10">
            {html_options options=$category_options selected=$category_options_selected}
          </select>
        </li>

        <li><label><input type="checkbox" name="subcats-included" value="1"
              {if $introduction.subcats_included}checked="checked" {/if}> {'Search in sub-albums'|translate}</label></li>
      </ul>
    </fieldset>

    <p class="bottomButtons syncBtn">
      <button class="icon-exchange buttonGradient" type="submit" value="" name="submit"> {'Synchronize'|translate}
      </button>
    </p>
  </form>
{/if}{*isset $introduction*}