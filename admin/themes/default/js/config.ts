interface PwgConfig {
    wsUrl: string;
    adminUrl: string;
}

const raw = document.getElementById('pwg-config')?.textContent ?? '{}';
export const config: PwgConfig = JSON.parse(raw);
