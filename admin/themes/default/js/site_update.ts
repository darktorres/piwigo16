import { initModule } from './moduleInit.js';

interface SyncCompleteData {
    simulate?: boolean;
    update?: { new_categories: number; new_elements: number; del_categories: number; del_elements: number; errors: number };
    metadata?: { updated: number; candidates: number; errors: number };
}

interface SyncPhaseData {
    phase: string; elapsed?: number;
    new?: number; deleted?: number; updated?: number; skipped?: number;
    dir?: string; current?: number; total?: number; file?: string;
}

interface SyncSubstepData {
    phase: string; id: string; label?: string; detail?: string;
    elapsed?: number; has_progress?: boolean;
}

type SyncEvent =
    | { type: 'phase_start'; data: SyncPhaseData }
    | { type: 'phase_progress'; data: SyncPhaseData }
    | { type: 'phase_complete'; data: SyncPhaseData }
    | { type: 'substep_start'; data: SyncSubstepData }
    | { type: 'substep_progress'; data: SyncSubstepData }
    | { type: 'substep_complete'; data: SyncSubstepData }
    | { type: 'complete'; data: SyncCompleteData }
    | { type: 'error'; data: { message: string } };

export function init(_cfg: Record<string, unknown>): void {
    document.querySelectorAll<HTMLElement>('#syncFiles label').forEach(function (el) {
        el.addEventListener('click', function () {
            const filesInput = document.querySelector<HTMLInputElement>("input[value='files']");
            const subList = filesInput?.closest("li")?.querySelector<HTMLElement>("ul");
            if (subList) subList.style.display = document.querySelector("input[value='files']:checked") ? '' : 'none';
        });
    });

    const form = document.getElementById('update') as HTMLFormElement | null;
    if (!form) return;

    const progress = document.getElementById('syncProgress');
    const phases = document.getElementById('syncPhases');
    let startTime = 0;
    let timerInterval = 0;
    let activeSubstepId: string | null = null;
    let activeSubstepStart = 0;

    const phaseLabels: Record<string, string> = {
        dirs: 'Scanning directories',
        files: 'Scanning and syncing files',
        meta: 'Syncing metadata',
    };

    let abortController: AbortController;
    let paused = false;
    let resumeResolve: (() => void) | null = null;
    let aborted = false;
    let syncReader: ReadableStreamDefaultReader<Uint8Array>;
    let syncRunning = false, syncComplete = false;

    window.addEventListener('beforeunload', function (e) { if (syncRunning) e.preventDefault(); });

    form.addEventListener('submit', function (e) {
        const syncChecked = document.querySelector<HTMLInputElement>("input[name='sync']:checked");
        const syncVal = syncChecked?.value ?? '';
        const syncMetaEl = document.querySelector<HTMLInputElement>("input[name='sync_meta']");
        const syncMeta = syncMetaEl?.checked ?? false;
        if (!syncVal && !syncMeta) return;

        e.preventDefault();
        syncRunning = true;
        form.style.display = 'none';
        if (progress) progress.style.display = '';
        const syncControls = document.getElementById('syncControls');
        if (syncControls) syncControls.style.display = '';
        startTime = performance.now();
        timerInterval = window.setInterval(updateElapsed, 100);
        paused = false; aborted = false; resumeResolve = null;

        abortController = new AbortController();
        const url = new URL(window.location.href);
        url.searchParams.set('sse', '1');
        const formData = new FormData(form);
        formData.append('submit', 'Synchronize');

        fetch(url.toString(), { method: 'POST', body: formData, signal: abortController.signal })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                syncReader = response.body!.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                function pump(): Promise<void> {
                    if (paused) {
                        return new Promise<void>(resolve => { resumeResolve = resolve; }).then(pump);
                    }
                    return syncReader.read().then(function (result) {
                        if (result.done) {
                            if (!syncComplete) onError('Server process ended unexpectedly. Check PHP error log for details.');
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
                if (aborted || syncComplete) return;
                syncRunning = false;
                clearInterval(timerInterval);
                updateElapsed();
                hideControls();
                const syncTitle = document.getElementById('syncTitle');
                if (syncTitle) syncTitle.textContent = 'Connection lost';
                const syncResults = document.getElementById('syncResults');
                if (syncResults) {
                    syncResults.innerHTML = '<div class="errors"><ul><li>The connection to the server was lost. The sync may still be running in the background. Refresh the page to check the current state.</li></ul></div><p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Refresh</button></p>';
                    syncResults.style.display = '';
                }
                void err;
            });
    });

    document.getElementById('syncPause')?.addEventListener('click', function () {
        const btn = document.getElementById('syncPause') as HTMLButtonElement;
        if (paused) {
            paused = false;
            btn.textContent = 'Pause';
            document.querySelectorAll('.syncPausedLabel').forEach(el => el.remove());
            if (resumeResolve) { const fn = resumeResolve; resumeResolve = null; fn(); }
        } else {
            paused = true;
            btn.textContent = 'Resume';
            document.getElementById('syncElapsed')?.insertAdjacentHTML('afterend', '<span class="syncPausedLabel">PAUSED</span>');
        }
    });

    document.getElementById('syncAbort')?.addEventListener('click', function () {
        aborted = true; syncRunning = false;
        if (paused && resumeResolve) { paused = false; const fn = resumeResolve; resumeResolve = null; fn(); }
        abortController.abort();
        clearInterval(timerInterval);
        updateElapsed();
        hideControls();
        const syncTitle = document.getElementById('syncTitle');
        if (syncTitle) syncTitle.textContent = 'Synchronization aborted';
        document.querySelectorAll<HTMLElement>('.sync-phase.running').forEach(el => { el.classList.remove('running'); el.classList.add('aborted'); });
        document.querySelectorAll('.sync-phase.aborted .phase-status').forEach(el => { el.innerHTML = '\u2717'; });
        const syncResults = document.getElementById('syncResults');
        if (syncResults) {
            syncResults.innerHTML = '<p>The synchronization was aborted. Any changes already committed to the database will remain.</p><p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Back to sync</button></p>';
            syncResults.style.display = '';
        }
    });

    function hideControls(): void {
        const el = document.getElementById('syncControls'); if (el) el.style.display = 'none';
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
        const e = { type: event, data } as unknown as SyncEvent;
        if (event === 'phase_start') onPhaseStart(e.data as SyncPhaseData);
        else if (event === 'phase_progress') onPhaseProgress(e.data as SyncPhaseData);
        else if (event === 'phase_complete') onPhaseComplete(e.data as SyncPhaseData);
        else if (event === 'substep_start') onSubstepStart(e.data as SyncSubstepData);
        else if (event === 'substep_progress') onSubstepProgress(e.data as SyncSubstepData);
        else if (event === 'substep_complete') onSubstepComplete(e.data as SyncSubstepData);
        else if (event === 'complete') onComplete(e.data as SyncCompleteData);
        else if (event === 'error') onError((e.data as { message: string }).message);
    }

    function onPhaseStart(data: SyncPhaseData): void {
        const label = phaseLabels[data.phase] ?? data.phase;
        phases?.insertAdjacentHTML('beforeend',
            '<div class="sync-phase running" id="phase-' + data.phase + '">' +
            '<span class="phase-status"><span class="spinner"></span></span>' +
            '<span class="phase-label">' + label + '</span>' +
            '<span class="phase-detail"></span><span class="phase-time"></span></div>');
    }

    function onSubstepStart(data: SyncSubstepData): void {
        const id = 'substep-' + data.phase + '-' + data.id;
        activeSubstepId = id; activeSubstepStart = performance.now();
        let h = '<div class="sync-substep running" id="' + id + '">' +
            '<span class="substep-status"><span class="spinner"></span></span>' +
            '<span class="substep-label">' + (data.label ?? '') + '</span>' +
            '<span class="substep-detail"></span><span class="substep-time"></span>';
        if (data.has_progress) h += '<div class="sync-progress-bar"><div class="progress-track"><div class="progress-fill"></div></div><span class="progress-text"></span></div>';
        h += '</div>';
        phases?.insertAdjacentHTML('beforeend', h);
    }

    function onSubstepProgress(data: SyncSubstepData): void {
        const el = document.getElementById('substep-' + data.phase + '-' + data.id);
        if (el && data.detail) el.querySelector('.substep-detail')!.textContent = data.detail;
    }

    function onSubstepComplete(data: SyncSubstepData): void {
        const id = 'substep-' + data.phase + '-' + data.id;
        const el = document.getElementById(id); if (!el) return;
        el.classList.remove('running'); el.classList.add('done');
        el.querySelector('.substep-status')!.innerHTML = '\u2713';
        if (data.detail) el.querySelector('.substep-detail')!.textContent = data.detail;
        if (data.elapsed !== undefined) el.querySelector('.substep-time')!.textContent = data.elapsed + 's';
        if (activeSubstepId === id) activeSubstepId = null;
    }

    function onPhaseProgress(data: SyncPhaseData): void {
        const phaseEl = document.getElementById('phase-' + data.phase); if (!phaseEl) return;
        if (data.phase === 'dirs' && data.dir) {
            phaseEl.querySelector('.phase-detail')!.textContent = data.dir;
        } else if (data.phase === 'meta' && data.current !== undefined) {
            const sub = document.getElementById('substep-meta-extract'); if (!sub) return;
            const pct = data.total! > 0 ? Math.round((data.current / data.total!) * 100) : 0;
            (sub.querySelector('.progress-fill') as HTMLElement).style.width = pct + '%';
            let infoText = fmt(data.current) + ' / ' + fmt(data.total!);
            if (data.file) infoText += ' \u2014 ' + data.file;
            sub.querySelector('.progress-text')!.textContent = infoText;
            sub.querySelector('.substep-detail')!.textContent = pct + '% \u2014 ' + data.updated + ' updated, ' + fmt(data.skipped!) + ' skipped';
        }
    }

    function onPhaseComplete(data: SyncPhaseData): void {
        const phaseEl = document.getElementById('phase-' + data.phase); if (!phaseEl) return;
        phaseEl.classList.remove('running'); phaseEl.classList.add('done');
        phaseEl.querySelector('.phase-status')!.innerHTML = '\u2713';
        phaseEl.querySelector('.phase-time')!.textContent = (data.elapsed ?? 0) + 's';
        const p: string[] = [];
        if ((data.new ?? 0) > 0) p.push(data.new + ' new');
        if ((data.deleted ?? 0) > 0) p.push(data.deleted + ' deleted');
        if (data.phase === 'meta') {
            phaseEl.querySelector('.phase-detail')!.textContent = data.updated + ' updated, ' + fmt(data.skipped!) + ' skipped';
        } else {
            phaseEl.querySelector('.phase-detail')!.textContent = p.length ? p.join(', ') : 'no changes';
        }
    }

    function onComplete(data: SyncCompleteData): void {
        syncComplete = true; syncRunning = false;
        clearInterval(timerInterval); updateElapsed(); hideControls();
        const syncTitle = document.getElementById('syncTitle');
        if (syncTitle) syncTitle.textContent = data.simulate ? '[Simulation] Synchronization complete' : 'Synchronization complete';

        let h = '';
        if (data.update) {
            h += '<h4>File synchronization</h4><ul>' +
                '<li>' + data.update.new_categories + ' albums added</li>' +
                '<li>' + data.update.new_elements + ' photos added</li>' +
                '<li>' + data.update.del_categories + ' albums deleted</li>' +
                '<li>' + data.update.del_elements + ' photos deleted</li>';
            if (data.update.errors > 0) h += '<li style="color:#dc3232">' + data.update.errors + ' errors</li>';
            h += '</ul>';
        }
        if (data.metadata) {
            h += '<h4>Metadata synchronization</h4><ul>' +
                '<li>' + data.metadata.updated + ' photos updated</li>' +
                '<li>' + data.metadata.candidates + ' candidates</li>';
            if (data.metadata.errors > 0) h += '<li style="color:#dc3232">' + data.metadata.errors + ' errors</li>';
            h += '</ul>';
        }
        h += '<p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Run again</button></p>';
        const syncResults = document.getElementById('syncResults');
        if (syncResults) { syncResults.innerHTML = h; syncResults.style.display = ''; }
    }

    function onError(msg: string): void {
        syncRunning = false; clearInterval(timerInterval);
        const syncTitle = document.getElementById('syncTitle'); if (syncTitle) syncTitle.textContent = 'Synchronization failed';
        const syncResults = document.getElementById('syncResults');
        if (syncResults) {
            syncResults.innerHTML = '<div class="errors"><ul><li>' + msg + '</li></ul></div><p class="bottomButtons"><button class="icon-exchange buttonGradient" type="button" onclick="location.reload()">Try again</button></p>';
            syncResults.style.display = '';
        }
    }

    function updateElapsed(): void {
        const now = performance.now();
        const syncElapsed = document.getElementById('syncElapsed');
        if (syncElapsed) syncElapsed.textContent = fmtTime((now - startTime) / 1000);
        if (activeSubstepId) {
            const subEl = document.getElementById(activeSubstepId);
            if (subEl) subEl.querySelector('.substep-time')!.textContent = fmtTime((now - activeSubstepStart) / 1000);
        }
    }

    function fmtTime(s: number): string {
        return s < 60 ? s.toFixed(1) + 's' : Math.floor(s / 60) + 'm ' + Math.floor(s % 60) + 's';
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && startTime && timerInterval) updateElapsed();
    });

    function fmt(n: number): string { return n.toLocaleString(); }
}

initModule(init);
