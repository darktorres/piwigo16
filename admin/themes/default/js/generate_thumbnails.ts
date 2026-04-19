import { initModule } from './moduleInit.js';

interface GtStrings {
    unexpected_end?: string;
    connection_lost?: string;
    try_again?: string;
    aborted?: string;
    aborted_message?: string;
    back?: string;
    generating?: string;
    checking?: string;
    done?: string;
    error?: string;
    thumbnails_generated?: string;
    skipped_reason?: string;
    run_again?: string;
    generated?: string;
    skipped?: string;
}

interface GtProgressData {
    current?: number;
    total?: number;
    file?: string;
    generated?: number;
    skipped?: number;
    elapsed?: number;
}

interface GtDoneData {
    elapsed?: number;
    generated?: number;
    skipped?: number;
}

interface GtConfig {
    gtStrings?: GtStrings;
}

export function init(cfg: GtConfig): void {
    const { gtStrings = {} } = cfg;

    const form = document.getElementById('gtForm') as HTMLFormElement | null;
    if (!form) return;

    const progress = document.getElementById('gtProgress');
    let startTime = 0;
    let timerInterval = 0;
    let abortController: AbortController;
    let aborted = false;
    let gtReader: ReadableStreamDefaultReader<Uint8Array>;
    let gtRunning = false, gtComplete = false;

    window.addEventListener('beforeunload', function (e) {
        if (gtRunning) e.preventDefault();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        gtRunning = true;
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
                gtReader = response.body!.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                function pump(): Promise<void> {
                    return gtReader.read().then(function (result) {
                        if (result.done) {
                            if (!gtComplete) onError(gtStrings.unexpected_end ?? 'Unexpected end');
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
                if (aborted || gtComplete) return;
                gtRunning = false;
                clearInterval(timerInterval);
                hideControls();
                const title = document.getElementById('gtTitle');
                if (title) title.textContent = gtStrings.connection_lost ?? 'Connection lost';
                const results = document.getElementById('gtResults');
                if (results) {
                    results.innerHTML = '<div class="errors"><ul><li>' + (gtStrings.connection_lost ?? '') + '</li></ul></div>'
                        + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (gtStrings.try_again ?? 'Try again') + '</button></p>';
                    results.style.display = '';
                }
                void err;
            });
    });

    const abortBtn = document.getElementById('gtAbort');
    if (abortBtn) {
        abortBtn.addEventListener('click', function () {
            aborted = true;
            gtRunning = false;
            abortController.abort();
            clearInterval(timerInterval);
            hideControls();
            const title = document.getElementById('gtTitle');
            if (title) title.textContent = gtStrings.aborted ?? 'Aborted';
            document.querySelectorAll<HTMLElement>('.sync-phase.running').forEach(function (el) {
                el.classList.remove('running');
                el.classList.add('aborted');
                el.querySelector('.phase-status')!.innerHTML = '\u2717';
            });
            const results = document.getElementById('gtResults');
            if (results) {
                results.innerHTML = '<p>' + (gtStrings.aborted_message ?? '') + '</p>'
                    + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (gtStrings.back ?? 'Back') + '</button></p>';
                results.style.display = '';
            }
        });
    }

    function hideControls(): void {
        const c = document.getElementById('gtControls');
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
        const phases = document.getElementById('gtPhases');
        if (!phases) return;
        phases.innerHTML = '<div class="sync-phase running" id="phase-generate">'
            + '<span class="phase-status"><span class="spinner"></span></span>'
            + '<span class="phase-label">' + (gtStrings.generating ?? '') + '</span>'
            + '<span class="phase-detail"></span><span class="phase-time"></span></div>'
            + '<div class="sync-substep running" id="substep-generate">'
            + '<span class="substep-status"><span class="spinner"></span></span>'
            + '<span class="substep-label">' + (gtStrings.checking ?? '') + '</span>'
            + '<span class="substep-detail"></span><span class="substep-time"></span>'
            + '<div class="sync-progress-bar"><div class="progress-track"><div class="progress-fill" id="gtProgressFill"></div></div>'
            + '<span class="progress-text" id="gtProgressText"></span></div>'
            + '<div class="gt-current-file" id="gtCurrentFile"></div></div>';
        const c = document.getElementById('gtControls');
        if (c) c.style.display = '';
    }

    function onProgress(data: GtProgressData): void {
        const current = data.current ?? 0;
        const total = data.total ?? 0;
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        const fill = document.getElementById('gtProgressFill');
        if (fill) fill.style.width = pct + '%';
        const text = document.getElementById('gtProgressText');
        if (text) text.textContent = current + ' / ' + total;
        const fileLabel = document.getElementById('gtCurrentFile');
        if (fileLabel) fileLabel.textContent = data.file ?? '';
        const generated = data.generated ?? 0;
        const skipped = data.skipped ?? 0;
        const sub = document.getElementById('substep-generate');
        if (sub) {
            sub.querySelector('.substep-detail')!.textContent =
                pct + '% \u2014 ' + String(generated) + ' ' + (gtStrings.generated ?? '')
                + (skipped > 0 ? ', ' + String(skipped) + ' ' + (gtStrings.skipped ?? '') : '');
        }
    }

    function onComplete(data: GtDoneData): void {
        gtComplete = true;
        gtRunning = false;
        clearInterval(timerInterval);
        updateElapsed();
        hideControls();

        const elapsed = String(data.elapsed ?? '');
        const generated = String(data.generated ?? 0);
        const skipped = data.skipped ?? 0;

        const phaseEl = document.getElementById('phase-generate');
        if (phaseEl) {
            phaseEl.classList.remove('running');
            phaseEl.classList.add('done');
            phaseEl.querySelector('.phase-status')!.innerHTML = '\u2713';
            phaseEl.querySelector('.phase-time')!.textContent = elapsed + 's';
            phaseEl.querySelector('.phase-detail')!.textContent =
                generated + ' ' + (gtStrings.generated ?? '')
                + (skipped > 0 ? ', ' + String(skipped) + ' ' + (gtStrings.skipped ?? '') : '');
        }
        const sub = document.getElementById('substep-generate');
        if (sub) {
            sub.classList.remove('running');
            sub.classList.add('done');
            sub.querySelector('.substep-status')!.innerHTML = '\u2713';
        }

        const title = document.getElementById('gtTitle');
        if (title) title.textContent = gtStrings.done ?? 'Done';

        let h = '<ul><li>' + generated + ' ' + (gtStrings.thumbnails_generated ?? '') + '</li>';
        if (skipped > 0) h += '<li>' + String(skipped) + ' ' + (gtStrings.skipped_reason ?? '') + '</li>';
        h += '</ul><p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (gtStrings.run_again ?? 'Run again') + '</button></p>';

        const results = document.getElementById('gtResults');
        if (results) { results.innerHTML = h; results.style.display = ''; }
    }

    function onError(msg: string): void {
        gtRunning = false;
        clearInterval(timerInterval);
        hideControls();
        const title = document.getElementById('gtTitle');
        if (title) title.textContent = gtStrings.error ?? 'Error';
        const results = document.getElementById('gtResults');
        if (results) {
            results.innerHTML = '<div class="errors"><ul><li>' + msg + '</li></ul></div>'
                + '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">' + (gtStrings.try_again ?? 'Try again') + '</button></p>';
            results.style.display = '';
        }
    }

    function updateElapsed(): void {
        const el = document.getElementById('gtElapsed');
        if (el && startTime) el.textContent = fmtTime((performance.now() - startTime) / 1000);
    }

    function fmtTime(s: number): string {
        return s < 60 ? s.toFixed(1) + 's' : Math.floor(s / 60) + 'm ' + Math.floor(s % 60) + 's';
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
