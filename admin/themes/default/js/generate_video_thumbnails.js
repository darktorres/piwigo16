import { initModule } from './moduleInit.js';

export function init(cfg) {
  const { vtStrings } = cfg;

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
            if (!vtComplete) onError(vtStrings.unexpected_end);
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
      if (title) title.textContent = vtStrings.connection_lost;
      var results = document.getElementById('vtResults');
      if (results) {
        results.innerHTML = '<div class="errors"><ul><li>' + vtStrings.connection_lost + '</li></ul></div>'
          + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + vtStrings.try_again + '</button></p>';
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
      if (title) title.textContent = vtStrings.aborted;
      document.querySelectorAll('.sync-phase.running').forEach(function(el) {
        el.classList.remove('running');
        el.classList.add('aborted');
        el.querySelector('.phase-status').innerHTML = '\u2717';
      });
      var results = document.getElementById('vtResults');
      if (results) {
        results.innerHTML = '<p>' + vtStrings.aborted_message + '</p>'
          + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + vtStrings.back + '</button></p>';
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
      + '<span class="phase-label">' + vtStrings.generating + '</span>'
      + '<span class="phase-detail"></span>'
      + '<span class="phase-time"></span>'
      + '</div>'
      + '<div class="sync-substep running" id="substep-generate">'
      + '<span class="substep-status"><span class="spinner"></span></span>'
      + '<span class="substep-label">' + vtStrings.extracting + '</span>'
      + '<span class="substep-detail"></span>'
      + '<span class="substep-time"></span>'
      + '<div class="sync-progress-bar">'
      + '<div class="progress-track"><div class="progress-fill" id="vtProgressFill"></div></div>'
      + '<span class="progress-text" id="vtProgressText"></span>'
      + '</div>'
      + '<div class="vt-current-file" id="vtCurrentFile"></div>'
      + '</div>';
    phases.innerHTML = h;
    var c = document.getElementById('vtControls');
    if (c) c.style.display = '';
  }

  function onProgress(data) {
    var pct = data.total > 0 ? Math.round((data.current / data.total) * 100) : 0;
    var fill = document.getElementById('vtProgressFill');
    if (fill) fill.style.width = pct + '%';
    var text = document.getElementById('vtProgressText');
    if (text) text.textContent = data.current + ' / ' + data.total;
    var fileLabel = document.getElementById('vtCurrentFile');
    if (fileLabel) fileLabel.textContent = data.file || '';
    if (data.skip_reason) {
      var list = document.getElementById('vtSkippedList');
      if (list) {
        var entry = document.createElement('div');
        entry.className = 'vt-skip-entry';
        if (data.skip_reason === 'file_not_found') {
          entry.textContent = data.file + ' \u2014 ' + vtStrings.file_not_found;
        } else if (data.ffmpeg_output && data.ffmpeg_output.length) {
          var header = document.createElement('div');
          header.textContent = data.file + ' \u2014 ' + vtStrings.ffmpeg_output + ':';
          entry.appendChild(header);
          var pre = document.createElement('pre');
          pre.className = 'vt-skip-ffmpeg-output';
          pre.textContent = data.ffmpeg_output.join('\n');
          entry.appendChild(pre);
        } else {
          entry.textContent = data.file + ' \u2014 ' + vtStrings.ffmpeg_no_output;
        }
        list.appendChild(entry);
        list.style.display = '';
      }
    }
    var sub = document.getElementById('substep-generate');
    if (sub) sub.querySelector('.substep-detail').textContent =
      pct + '% \u2014 ' + data.generated + ' ' + vtStrings.generated + (data.skipped > 0 ? ', ' + data.skipped + ' ' + vtStrings.skipped : '');
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
        data.generated + ' ' + vtStrings.generated + (data.skipped > 0 ? ', ' + data.skipped + ' ' + vtStrings.skipped : '');
    }
    var sub = document.getElementById('substep-generate');
    if (sub) {
      sub.classList.remove('running');
      sub.classList.add('done');
      sub.querySelector('.substep-status').innerHTML = '\u2713';
    }

    var title = document.getElementById('vtTitle');
    if (title) title.textContent = vtStrings.done;

    var h = '<ul>'
      + '<li>' + data.generated + ' ' + vtStrings.thumbnails_generated + '</li>';
    if (data.skipped > 0) {
      h += '<li>' + data.skipped + ' ' + vtStrings.skipped_reason + '</li>';
    }
    h += '</ul>'
      + '<p class="bottomButtons">'
      + '<button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + vtStrings.run_again + '</button></p>';

    var results = document.getElementById('vtResults');
    if (results) { results.innerHTML = h; results.style.display = ''; }
  }

  function onError(msg) {
    vtRunning = false;
    clearInterval(timerInterval);
    hideControls();
    var title = document.getElementById('vtTitle');
    if (title) title.textContent = vtStrings.error;
    var results = document.getElementById('vtResults');
    if (results) {
      results.innerHTML = '<div class="errors"><ul><li>' + msg + '</li></ul></div>'
        + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + vtStrings.try_again + '</button></p>';
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
}

initModule(init);
