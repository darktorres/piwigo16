export const GeoIp = {
  cache: {},
  pending: {},

  get: function (ip, callback) {
    if (!GeoIp.storageInit && window.localStorage) {
      GeoIp.storageInit = true;
      const cache = localStorage.getItem("freegeoip");
      if (cache) {
        const parsed = JSON.parse(cache);
        for (const key in parsed) {
          const data = parsed[key];
          if (new Date().getTime() - data.reqTime > 96 * 3600000)
            delete parsed[key];
        }
        GeoIp.cache = parsed;
      }
      window.addEventListener("pagehide", function () {
        localStorage.setItem("freegeoip", JSON.stringify(GeoIp.cache));
      });
    }

    if (Object.hasOwn(GeoIp.cache, ip)) callback(GeoIp.cache[ip]);
    else if (GeoIp.pending[ip]) GeoIp.pending[ip].push(callback);
    else {
      GeoIp.pending[ip] = [callback];
      fetch("https://ipapi.co/" + ip + "/json/")
        .then(function (response) { return response.json(); })
        .then(function (data) {
          data.reqTime = new Date().getTime();
          const res = [];
          if (data.city) res.push(data.city);
          if (data.region) res.push(data.region);
          if (data.country_name) res.push(data.country_name);
          data.fullName = res.join(", ");

          GeoIp.cache[ip] = data;
          const callbacks = GeoIp.pending[ip];
          delete GeoIp.pending[ip];
          for (let i = 0; i < callbacks.length; i++)
            callbacks[i].call(null, data);
        })
        .catch(function () {
          const data = { ip: ip, reqTime: new Date().getTime() };
          GeoIp.cache[ip] = data;
          const callbacks = GeoIp.pending[ip];
          delete GeoIp.pending[ip];
          for (let i = 0; i < callbacks.length; i++)
            callbacks[i].call(null, data);
        });
    }
  },
};
