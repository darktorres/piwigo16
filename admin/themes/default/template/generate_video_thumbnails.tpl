{footer_script}<script>
(function() {
  var form = document.getElementById('vtForm');
  if (!form) return;

  var progress = document.getElementById('vtProgress');
  var startTime, timerInterval;
  var abortController, aborted, vtReader;
  var vtRunning = false, vtComplete = false;

  window.addEventListener('beforeunload', function(e) {
    if (vtRunning) e.preventDefault();
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    vtRunning = true;
    form.style.display = 'none';
    progress.style.display = '';
    startTime = performance.now();
    timerInterval = setInterval(updateElapsed, 100);
    aborted = false;
    abortController = new AbortController();

    var url = new URL(window.location.href);
    url.searchParams.set('sse', '1');
    var formData = new FormData(form);
    formData.append('submit', '1');

    fetch(url.toString(), {
      method: 'POST',
      body: formData,
      signal: abortController.signal
    }).then(function(response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      vtReader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      function pump() {
        return vtReader.read().then(function(result) {
          if (result.done) {
            if (!vtComplete) onError('Server process ended unexpectedly. Check PHP error log for details.');
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
      if (aborted || vtComplete) return;
      vtRunning = false;
      clearInterval(timerInterval);
      hideControls();
      var title = document.getElementById('vtTitle');
      if (title) title.textContent = 'Connection lost';
      var results = document.getElementById('vtResults');
      if (results) {
        results.innerHTML = '<div class="errors"><ul><li>The connection to the server was lost.</li></ul></div>'
          + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Try again</button></p>';
        results.style.display = '';
      }
    });
  });

  var abortBtn = document.getElementById('vtAbort');
  if (abortBtn) {
    abortBtn.addEventListener('click', function() {
      aborted = true;
      vtRunning = false;
      abortController.abort();
      clearInterval(timerInterval);
      hideControls();
      var title = document.getElementById('vtTitle');
      if (title) title.textContent = 'Aborted';
      document.querySelectorAll('.sync-phase.running').forEach(function(el) {
        el.classList.remove('running');
        el.classList.add('aborted');
        el.querySelector('.phase-status').innerHTML = '\u2717';
      });
      var results = document.getElementById('vtResults');
      if (results) {
        results.innerHTML = '<p>Aborted. Any thumbnails already generated are saved.</p>'
          + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Back</button></p>';
        results.style.display = '';
      }
    });
  }

  function hideControls() {
    var c = document.getElementById('vtControls');
    if (c) c.style.display = 'none';
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
    try { handleEvent(name, JSON.parse(data)); }
    catch(ex) { console.error('SSE parse error', ex, data); }
  }

  function handleEvent(event, data) {
    if (event === 'start') onStart(data);
    else if (event === 'progress') onProgress(data);
    else if (event === 'complete') onComplete(data);
    else if (event === 'error') onError(data.message);
  }

  function onStart(data) {
    var phases = document.getElementById('vtPhases');
    if (!phases) return;
    var h = '<div class="sync-phase running" id="phase-generate">'
      + '<span class="phase-status"><span class="spinner"></span></span>'
      + '<span class="phase-label">{'Generating video thumbnails'|translate|escape:'javascript'}</span>'
      + '<span class="phase-detail"></span>'
      + '<span class="phase-time"></span>'
      + '</div>'
      + '<div class="sync-substep running" id="substep-generate">'
      + '<span class="substep-status"><span class="spinner"></span></span>'
      + '<span class="substep-label">{'Extracting frames with FFmpeg'|translate|escape:'javascript'}</span>'
      + '<span class="substep-detail"></span>'
      + '<span class="substep-time"></span>'
      + '<div class="sync-progress-bar">'
      + '<div class="progress-track"><div class="progress-fill" id="vtProgressFill"></div></div>'
      + '<span class="progress-text" id="vtProgressText"></span>'
      + '</div></div>';
    phases.innerHTML = h;
    var c = document.getElementById('vtControls');
    if (c) c.style.display = '';
  }

  function onProgress(data) {
    var pct = data.total > 0 ? Math.round((data.current / data.total) * 100) : 0;
    var fill = document.getElementById('vtProgressFill');
    if (fill) fill.style.width = pct + '%';
    var text = document.getElementById('vtProgressText');
    if (text) text.textContent = data.current + ' / ' + data.total + (data.file ? ' \u2014 ' + data.file : '');
    var sub = document.getElementById('substep-generate');
    if (sub) sub.querySelector('.substep-detail').textContent =
      pct + '% \u2014 ' + data.generated + ' {'generated'|translate|escape:'javascript'}' + (data.skipped > 0 ? ', ' + data.skipped + ' {'skipped'|translate|escape:'javascript'}' : '');
  }

  function onComplete(data) {
    vtComplete = true;
    vtRunning = false;
    clearInterval(timerInterval);
    updateElapsed();
    hideControls();

    var phaseEl = document.getElementById('phase-generate');
    if (phaseEl) {
      phaseEl.classList.remove('running');
      phaseEl.classList.add('done');
      phaseEl.querySelector('.phase-status').innerHTML = '\u2713';
      phaseEl.querySelector('.phase-time').textContent = data.elapsed + 's';
      phaseEl.querySelector('.phase-detail').textContent =
        data.generated + ' {'generated'|translate|escape:'javascript'}' + (data.skipped > 0 ? ', ' + data.skipped + ' {'skipped'|translate|escape:'javascript'}' : '');
    }
    var sub = document.getElementById('substep-generate');
    if (sub) {
      sub.classList.remove('running');
      sub.classList.add('done');
      sub.querySelector('.substep-status').innerHTML = '\u2713';
    }

    var title = document.getElementById('vtTitle');
    if (title) title.textContent = '{'Done'|translate|escape:'javascript'}';

    var h = '<ul>'
      + '<li>' + data.generated + ' {'thumbnails generated'|translate|escape:'javascript'}</li>';
    if (data.skipped > 0) {
      h += '<li>' + data.skipped + ' {'skipped (file not found or FFmpeg unavailable)'|translate|escape:'javascript'}</li>';
    }
    h += '</ul>'
      + '<p class="bottomButtons">'
      + '<button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">{'Run again'|translate|escape:'javascript'}</button></p>';

    var results = document.getElementById('vtResults');
    if (results) { results.innerHTML = h; results.style.display = ''; }
  }

  function onError(msg) {
    vtRunning = false;
    clearInterval(timerInterval);
    hideControls();
    var title = document.getElementById('vtTitle');
    if (title) title.textContent = '{'Error'|translate|escape:'javascript'}';
    var results = document.getElementById('vtResults');
    if (results) {
      results.innerHTML = '<div class="errors"><ul><li>' + msg + '</li></ul></div>'
        + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">{'Try again'|translate|escape:'javascript'}</button></p>';
      results.style.display = '';
    }
  }

  function updateElapsed() {
    var el = document.getElementById('vtElapsed');
    if (el && startTime) el.textContent = fmtTime((performance.now() - startTime) / 1000);
  }

  function fmtTime(s) {
    return s < 60 ? s.toFixed(1) + 's' : Math.floor(s / 60) + 'm ' + Math.floor(s % 60) + 's';
  }
})();
</script>{/footer_script}

<style>
.sync-phase {
  display: flex; align-items: center; flex-wrap: wrap;
  padding: 10px 0; gap: 8px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sync-phase:last-child { border-bottom: none; }
.sync-phase .phase-status { width: 22px; text-align: center; flex-shrink: 0; color: #6bc46d; font-weight: bold; font-size: 16px; }
.sync-phase.running .phase-label { font-weight: 600; color: #fff; }
.sync-phase .phase-detail { color: #aaa; font-size: 13px; }
.sync-phase .phase-time { margin-left: auto; color: #999; font-size: 12px; }
.sync-progress-bar { width: 100%; display: flex; align-items: center; gap: 10px; margin-top: 2px; padding-left: 30px; }
.progress-track { flex: 1; height: 16px; background: #4a4a4a; border-radius: 8px; overflow: hidden; }
.progress-fill { height: 100%; background: linear-gradient(90deg, #0073aa, #00a0d2); border-radius: 8px; transition: width 0.3s ease; width: 0; }
.progress-text { font-size: 12px; color: #bbb; white-space: nowrap; }
.sync-elapsed { margin-top: 12px; color: #aaa; font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #555; border-top-color: #00a0d2; border-radius: 50%; animation: spin 0.8s linear infinite; }
.sync-substep { display: flex; align-items: center; flex-wrap: wrap; padding: 5px 0; padding-left: 32px; gap: 8px; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.05); }
.sync-substep:last-child { border-bottom: none; }
.sync-substep .substep-status { width: 18px; text-align: center; flex-shrink: 0; color: #6bc46d; font-weight: bold; font-size: 13px; }
.sync-substep .substep-status .spinner { width: 12px; height: 12px; }
.sync-substep.running .substep-label { color: #ddd; }
.sync-substep .substep-label { color: #999; }
.sync-substep .substep-detail { color: #888; font-size: 12px; }
.sync-substep .substep-time { margin-left: auto; color: #777; font-size: 11px; }
.sync-substep .sync-progress-bar { padding-left: 26px; }
.sync-phase.aborted .phase-status, .sync-substep.aborted .substep-status { color: #e25b5b; }
#vtControls { margin-left: 20px; }
.syncControlBtn { padding: 4px 14px; font-size: 12px; cursor: pointer; margin-right: 6px; border: none; border-radius: 3px; }
.syncAbortBtn { background: #b32d2e; color: #fff; }
.syncAbortBtn:hover { background: #9b2324; }
#vtResults ul { margin: 5px 0 5px 20px; }
</style>

<form id="vtForm" method="post" action="{$U_ACTION}">
  {html_input_field field='pwg_token' type='hidden' value=$pwg_token}
  {if $PENDING_COUNT > 0}
    <p>
      {sprintf('Found %d video(s) without a thumbnail. FFmpeg will extract a frame from each one.'|translate, $PENDING_COUNT)}
    </p>
  {else}
    <p>{'All videos already have thumbnails.'|translate}</p>
  {/if}
  <p class="bottomButtons">
    <button type="submit" class="icon-film buttonGradient"{if $PENDING_COUNT == 0} disabled{/if}>
      {'Generate video thumbnails'|translate}
    </button>
  </p>
</form>

<div id="vtProgress" style="display:none">
  <fieldset>
    <legend><span class="icon-film icon-blue"></span> <span id="vtTitle">{'Generating video thumbnails&hellip;'|translate}</span></legend>
    <div id="vtPhases"></div>
    <p class="sync-elapsed">
      {'Elapsed'|translate}: <strong id="vtElapsed">0.0s</strong>
      <span id="vtControls" style="display:none">
        <button type="button" id="vtAbort" class="buttonGradient syncControlBtn syncAbortBtn">{'Abort'|translate}</button>
      </span>
    </p>
  </fieldset>
  <div id="vtResults" style="display:none"></div>
</div>
