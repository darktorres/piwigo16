interface GeoIpData {
    ip?: string;
    city?: string;
    region?: string;
    country_name?: string;
    fullName?: string;
    reqTime: number;
    [key: string]: unknown;
}

interface GeoIpCache { [ip: string]: GeoIpData }

export const GeoIp = {
    cache: {} as GeoIpCache,
    pending: {} as Record<string, Array<(data: GeoIpData) => void>>,
    storageInit: false,

    get(ip: string, callback: (data: GeoIpData) => void): void {
        if (!GeoIp.storageInit) {
            GeoIp.storageInit = true;
            const cache = localStorage.getItem("freegeoip");
            if (cache) {
                const parsed = JSON.parse(cache) as GeoIpCache;
                const now = new Date().getTime();
                for (const key in parsed) {
                    if (now - (parsed[key]?.reqTime ?? 0) > 96 * 3600000) Reflect.deleteProperty(parsed, key);
                }
                GeoIp.cache = parsed;
            }
            window.addEventListener("pagehide", function () {
                localStorage.setItem("freegeoip", JSON.stringify(GeoIp.cache));
            });
        }

        if (Object.hasOwn(GeoIp.cache, ip)) {
            callback(GeoIp.cache[ip]!);
        } else if (GeoIp.pending[ip]) {
            GeoIp.pending[ip].push(callback);
        } else {
            GeoIp.pending[ip] = [callback];
            fetch("https://ipapi.co/" + ip + "/json/")
                .then(r => r.json())
                .then(function (data: GeoIpData) {
                    data.reqTime = new Date().getTime();
                    const res: string[] = [];
                    if (data.city) res.push(data.city);
                    if (data.region) res.push(data.region);
                    if (data.country_name) res.push(data.country_name);
                    data.fullName = res.join(", ");
                    GeoIp.cache[ip] = data;
                    const callbacks = GeoIp.pending[ip]!;
                    Reflect.deleteProperty(GeoIp.pending, ip);
                    for (const cb of callbacks) cb(data);
                })
                .catch(function () {
                    const data: GeoIpData = { ip, reqTime: new Date().getTime() };
                    GeoIp.cache[ip] = data;
                    const callbacks = GeoIp.pending[ip]!;
                    Reflect.deleteProperty(GeoIp.pending, ip);
                    for (const cb of callbacks) cb(data);
                });
        }
    },
};
