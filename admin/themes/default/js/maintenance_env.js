document.addEventListener('DOMContentLoaded', function () {
    fetch("ws.php?format=json&method=pwg.plugins.getList")
        .then(function (response) { return response.json(); })
        .then(function (data) {
            plugins = data.result;
            hasActivePlugins = false;
            nbActivatedPlugins = 0;
            plugins.forEach(function (plugin) {
                if (plugin.state == "active") {
                    hasActivePlugins = true;
                    var pluginList = document.querySelector("#pluginList ul");
                    if (pluginList) {
                        pluginList.insertAdjacentHTML("beforeend", "<li>" + plugin.name + "</li>");
                        var spinner = pluginList.querySelector("i");
                        if (spinner) spinner.style.display = 'none';
                    }
                    nbActivatedPlugins++;
                }
            });

            if (!hasActivePlugins) {
                var pluginList = document.querySelector("#pluginList ul");
                if (pluginList) {
                    var spinner = pluginList.querySelector("i");
                    if (spinner) spinner.style.display = 'none';
                    pluginList.insertAdjacentHTML("beforeend", "<p>" + no_active_plugin + "</p>");
                }
            }
            document.querySelectorAll(".badge-number").forEach(function (el) {
                el.textContent += nbActivatedPlugins;
            });
        })
        .catch(function () {
            document.querySelectorAll(".badge-number").forEach(function (el) {
                el.textContent += 0;
            });
            var pluginList = document.querySelector("#pluginList ul");
            if (pluginList) {
                pluginList.insertAdjacentHTML("beforeend", "<p>" + error_occurred + "</p>");
                var spinner = pluginList.querySelector("i");
                if (spinner) spinner.style.display = 'none';
            }
        });
});
