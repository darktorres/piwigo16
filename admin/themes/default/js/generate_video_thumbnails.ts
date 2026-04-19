import { initModule } from './moduleInit.js';

interface VtStrings {
    unexpected_end?: string;
    connection_lost?: string;
    try_again?: string;
    aborted?: string;
    aborted_message?: string;
    back?: string;
    generating?: string;
    extracting?: string;
    done?: string;
    error?: string;
    thumbnails_generated?: string;
    skipped_reason?: string;
    run_again?: string;
    generated?: string;
    skipped?: string;
    file_not_found?: string;
    ffmpeg_output?: string;
    ffmpeg_no_output?: string;
}

interface VtProgressData {
    current?: number;
    total?: number;
    file?: string;
    generated?: string;
    skipped?: number;
    skip_reason?: string;
    ffmpeg_output?: string[];
    elapsed?: number;
}

interface VtDoneData {
    elapsed?: number;
    generated?: number;
    skipped?: number;
}

interface VtConfig {
    vtStrings?: VtStrings;
}

export function init(cfg: VtConfig): void {
    const { vtStrings = {} } = cfg;

    const form = document.getElementById('vtForm') as HTMLFormElement | null;
    if (!form) return;

    const progress = document.getElementById('vtProgress');
    let startTime = 0;
    let timerInterval = 0;
    let abortController: AbortController;
    let aborted = false;
    let vtReader: ReadableStreamDefaultReader<Uint8Array>;
    let vtRunning = false, vtComplete = false;

    window.addEventListener('beforeunload', function (e) {
        if (vtRunning) e.preventDefault();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        vtRunning = true;
        form.style.display = 'none';
        if (progress) progress.style.display = '';
        startTime = performance.now();
        timerInterval = window.setInterval(updateElapsed, 100);
        aborted = false;
        abortController = new AbortController();

        const url = new URL(window.location.href);
        url.searchParams.set('sse', '1');
        const formData = new FormData(form);
        formData.append('submit', '1');

        fetch(url.toString(), { method: 'POST', body: formData, signal: abortController.signal })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                vtReader = response.body!.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                function pump(): Promise<void> {
                    return vtReader.read().then(function (result) {
                        if (result.done) {
                            if (!vtComplete) onError(vtStrings.unexpected_end ?? 'Unexpected end');
                            return;
                        }
                        buffer += decoder.decode(result.value, { stream: true });
                        const parts = buffer.split('\n\n');
                        buffer = parts.pop() ?? '';
                        parts.forEach(parseEvent);
                        return pump();
                    });
                }
                return pump();
            })
            .catch(function (err: unknown) {
                if (aborted || vtComplete) return;
                vtRunning = false;
                clearInterval(timerInterval);
                hideControls();
                const title = document.getElementById('vtTitle');
                if (title) title.textContent = vtStrings.connection_lost ?? 'Connection lost';
                const results = document.getElementById('vtResults');
                if (results) {
                    results.innerHTML = '<div class="errors"><ul><li>' + (vtStrings.connection_lost ?? '') + '</li></ul></div>'
                        + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (vtStrings.try_again ?? 'Try again') + '</button></p>';
                    results.style.display = '';
                }
                void err;
            });
    });

    const abortBtn = document.getElementById('vtAbort');
    if (abortBtn) {
        abortBtn.addEventListener('click', function () {
            aborted = true;
            vtRunning = false;
            abortController.abort();
            clearInterval(timerInterval);
            hideControls();
            const title = document.getElementById('vtTitle');
            if (title) title.textContent = vtStrings.aborted ?? 'Aborted';
            document.querySelectorAll<HTMLElement>('.sync-phase.running').forEach(function (el) {
                el.classList.remove('running');
                el.classList.add('aborted');
                el.querySelector('.phase-status')!.innerHTML = '\u2717';
            });
            const results = document.getElementById('vtResults');
            if (results) {
                results.innerHTML = '<p>' + (vtStrings.aborted_message ?? '') + '</p>'
                    + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (vtStrings.back ?? 'Back') + '</button></p>';
                results.style.display = '';
            }
        });
    }

    function hideControls(): void {
        const c = document.getElementById('vtControls');
        if (c) c.style.display = 'none';
    }

    function parseEvent(raw: string): void {
        if (!raw.trim()) return;
        const lines = raw.trim().split('\n');
        let name = '', data = '';
        for (const line of lines) {
            if (line.startsWith('event: ')) name = line.substring(7);
            else if (line.startsWith('data: ')) data = line.substring(6);
        }
        if (!name || !data) return;
        try { handleEvent(name, JSON.parse(data) as Record<string, unknown>); }
        catch (ex) { console.error('SSE parse error', ex, data); }
    }

    function handleEvent(event: string, data: Record<string, unknown>): void {
        if (event === 'start') onStart();
        else if (event === 'progress') onProgress(data);
        else if (event === 'complete') onComplete(data);
        else if (event === 'error') onError((data as { message: string }).message);
    }

    function onStart(): void {
        const phases = document.getElementById('vtPhases');
        if (!phases) return;
        phases.innerHTML = '<div class="sync-phase running" id="phase-generate">'
            + '<span class="phase-status"><span class="spinner"></span></span>'
            + '<span class="phase-label">' + (vtStrings.generating ?? '') + '</span>'
            + '<span class="phase-detail"></span><span class="phase-time"></span></div>'
            + '<div class="sync-substep running" id="substep-generate">'
            + '<span class="substep-status"><span class="spinner"></span></span>'
            + '<span class="substep-label">' + (vtStrings.extracting ?? '') + '</span>'
            + '<span class="substep-detail"></span><span class="substep-time"></span>'
            + '<div class="sync-progress-bar"><div class="progress-track"><div class="progress-fill" id="vtProgressFill"></div></div>'
            + '<span class="progress-text" id="vtProgressText"></span></div>'
            + '<div class="vt-current-file" id="vtCurrentFile"></div></div>';
        const c = document.getElementById('vtControls');
        if (c) c.style.display = '';
    }

    function onProgress(data: VtProgressData): void {
        const current = data.current ?? 0;
        const total = data.total ?? 0;
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        const fill = document.getElementById('vtProgressFill');
        if (fill) (fill).style.width = pct + '%';
        const text = document.getElementById('vtProgressText');
        if (text) text.textContent = current + ' / ' + total;
        const fileLabel = document.getElementById('vtCurrentFile');
        const file = data.file ?? '';
        const generated = data.generated ?? '';
        const skipped = data.skipped ?? 0;
        if (fileLabel) fileLabel.textContent = file;
        if (data.skip_reason) {
            const list = document.getElementById('vtSkippedList');
            if (list) {
                const entry = document.createElement('div');
                entry.className = 'vt-skip-entry';
                if (data.skip_reason === 'file_not_found') {
                    entry.textContent = file + ' \u2014 ' + (vtStrings.file_not_found ?? '');
                } else if (Array.isArray(data.ffmpeg_output) && data.ffmpeg_output.length) {
                    const header = document.createElement('div');
                    header.textContent = file + ' \u2014 ' + (vtStrings.ffmpeg_output ?? '') + ':';
                    entry.appendChild(header);
                    const pre = document.createElement('pre');
                    pre.className = 'vt-skip-ffmpeg-output';
                    pre.textContent = data.ffmpeg_output.join('\n');
                    entry.appendChild(pre);
                } else {
                    entry.textContent = file + ' \u2014 ' + (vtStrings.ffmpeg_no_output ?? '');
                }
                list.appendChild(entry);
                list.style.display = '';
            }
        }
        const sub = document.getElementById('substep-generate');
        if (sub) {
            sub.querySelector('.substep-detail')!.textContent =
                pct + '% \u2014 ' + generated + ' ' + (vtStrings.generated ?? '')
                + (skipped > 0 ? ', ' + String(skipped) + ' ' + (vtStrings.skipped ?? '') : '');
        }
    }

    function onComplete(data: VtDoneData): void {
        vtComplete = true;
        vtRunning = false;
        clearInterval(timerInterval);
        updateElapsed();
        hideControls();

        const elapsed = String(data.elapsed ?? '');
        const generated = String(data.generated ?? '');
        const skipped = data.skipped ?? 0;

        const phaseEl = document.getElementById('phase-generate');
        if (phaseEl) {
            phaseEl.classList.remove('running');
            phaseEl.classList.add('done');
            phaseEl.querySelector('.phase-status')!.innerHTML = '\u2713';
            phaseEl.querySelector('.phase-time')!.textContent = elapsed + 's';
            phaseEl.querySelector('.phase-detail')!.textContent =
                generated + ' ' + (vtStrings.generated ?? '')
                + (skipped > 0 ? ', ' + String(skipped) + ' ' + (vtStrings.skipped ?? '') : '');
        }
        const sub = document.getElementById('substep-generate');
        if (sub) {
            sub.classList.remove('running');
            sub.classList.add('done');
            sub.querySelector('.substep-status')!.innerHTML = '\u2713';
        }

        const title = document.getElementById('vtTitle');
        if (title) title.textContent = vtStrings.done ?? 'Done';

        let h = '<ul><li>' + generated + ' ' + (vtStrings.thumbnails_generated ?? '') + '</li>';
        if (skipped > 0) h += '<li>' + String(skipped) + ' ' + (vtStrings.skipped_reason ?? '') + '</li>';
        h += '</ul><p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (vtStrings.run_again ?? 'Run again') + '</button></p>';

        const results = document.getElementById('vtResults');
        if (results) { results.innerHTML = h; results.style.display = ''; }
    }

    function onError(msg: string): void {
        vtRunning = false;
        clearInterval(timerInterval);
        hideControls();
        const title = document.getElementById('vtTitle');
        if (title) title.textContent = vtStrings.error ?? 'Error';
        const results = document.getElementById('vtResults');
        if (results) {
            results.innerHTML = '<div class="errors"><ul><li>' + msg + '</li></ul></div>'
                + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (vtStrings.try_again ?? 'Try again') + '</button></p>';
            results.style.display = '';
        }
    }

    function updateElapsed(): void {
        const el = document.getElementById('vtElapsed');
        if (el && startTime) el.textContent = fmtTime((performance.now() - startTime) / 1000);
    }

    function fmtTime(s: number): string {
        return s < 60 ? s.toFixed(1) + 's' : Math.floor(s / 60) + 'm ' + Math.floor(s % 60) + 's';
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
