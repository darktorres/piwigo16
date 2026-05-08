interface PwgConfig {
    wsUrl: string;
}

const raw = document.getElementById('pwg-config')?.textContent ?? '{}';
export const config = JSON.parse(raw) as PwgConfig;
