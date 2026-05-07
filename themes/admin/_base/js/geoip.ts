interface GeoIpData {
    ip: string;
    reqTime: number;
    city?: string;
    region_name?: string;
    country_name?: string;
    latitude?: number;
    longitude?: number;
    fullName?: string;
}

const GeoIp = {
    cache: {} as Record<string, GeoIpData>,
    pending: {} as Record<string, Array<(data: GeoIpData) => void>>,
    storageInit: false,

    get(ip: string, callback: (data: GeoIpData) => void): void {
        if (!GeoIp.storageInit && window.localStorage) {
            GeoIp.storageInit = true;
            const cached = localStorage.getItem('freegeoip');
            if (cached) {
                const cacheObj = JSON.parse(cached) as Record<string, GeoIpData>;
                for (const key in cacheObj) {
                    if (new Date().getTime() - cacheObj[key].reqTime > 96 * 3600000) {
                        delete cacheObj[key];
                    }
                }
                GeoIp.cache = cacheObj;
            }
            window.addEventListener('beforeunload', () => {
                localStorage.setItem('freegeoip', JSON.stringify(GeoIp.cache));
            });
        }

        if (Object.prototype.hasOwnProperty.call(GeoIp.cache, ip)) {
            callback(GeoIp.cache[ip]);
        } else if (GeoIp.pending[ip]) {
            GeoIp.pending[ip].push(callback);
        } else {
            GeoIp.pending[ip] = [callback];
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 5000);
            fetch('https://freegeoip.net/json/' + ip, { signal: controller.signal })
                .then((r) => r.json())
                .then(
                    (
                        data: GeoIpData & {
                            city?: string;
                            region_name?: string;
                            country_name?: string;
                        }
                    ) => {
                        clearTimeout(timeout);
                        data.reqTime = new Date().getTime();
                        const res: string[] = [];
                        if (data.city) res.push(data.city);
                        if (data.region_name) res.push(data.region_name);
                        if (data.country_name) res.push(data.country_name);
                        data.fullName = res.join(', ');
                        GeoIp.cache[ip] = data;
                        const callbacks = GeoIp.pending[ip];
                        delete GeoIp.pending[ip];
                        callbacks.forEach((cb) => cb(data));
                    }
                )
                .catch(() => {
                    clearTimeout(timeout);
                    const data: GeoIpData = { ip, reqTime: new Date().getTime() };
                    GeoIp.cache[ip] = data;
                    const callbacks = GeoIp.pending[ip];
                    delete GeoIp.pending[ip];
                    callbacks.forEach((cb) => cb(data));
                });
        }
    },
};

(window as any).GeoIp = GeoIp;

export { GeoIp };
export type { GeoIpData };
