interface PwgConfig {
    wsUrl: string;
}

const raw = document.getElementById('pwg-config')?.textContent ?? '{}';
export const config: PwgConfig = JSON.parse(raw);
