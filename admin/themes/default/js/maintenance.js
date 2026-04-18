// Get config from JSON data block
const configEl = document.getElementById('pwg-page-data');
const cfg = configEl ? JSON.parse(configEl.textContent) : {};

const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

function displayResponse(domElem, values, mDivs, mValues) {
    const unit_MB = cfg.unit_MB || '%s MB';
    const no_time_elapsed = cfg.no_time_elapsed || 'right now';

    for (let index = 0; index < domElem.length; index++) {
        domElem[index].innerHTML = unit_MB.replace("%s", values[index]);
    }

    for (let index = 0; index < mDivs.length; index++) {
        mDivName = mDivs[index].getAttribute("name");
        mDivs[index].title = unit_MB.replace("%s", mValues[mDivName]);
    }

    var cacheLastCalc = document.querySelector(".cache-lastCalculated-value");
    if (cacheLastCalc) cacheLastCalc.innerHTML = no_time_elapsed;
}

_docReady( function () {
    document.querySelectorAll(".refresh-cache-size").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var refreshIcon = this.querySelector(".refresh-icon");
            if (refreshIcon) refreshIcon.classList.add("animate-spin");

            return new Promise((res, rej) => {
                fetch("ws.php?format=json&method=pwg.getCacheSize", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({ param: "test_param", service: "test_service" }),
                })
                    .then(function (response) { return response.text(); })
                    .then(function (raw) {
                        data = JSON.parse(raw);
                        if (data.stat === "ok") {
                            res();

                            var domElemToRefresh = [
                                document.querySelector(".cache-size-value"),
                                document.querySelector(".multiple-pictures-sizes"),
                                document.querySelector(".multiple-compiledTemplate-sizes"),
                            ];
                            var domElemValues = [
                                data.result.infos[0].value,
                                data.result.infos[1].value.all,
                                data.result.infos[2].value,
                            ];
                            for (let i = 0; i < domElemValues.length; i++) {
                                domElemValues[i] = (
                                    domElemValues[i] /
                                    1024 /
                                    1024
                                ).toFixed(2);
                            }

                            var deleteCheckContainer = document.querySelector(".delete-check-container");
                            var multipleSizes = deleteCheckContainer
                                ? deleteCheckContainer.querySelectorAll(".delete-size-check")
                                : [];
                            var multipleSizesValues = data.result.infos[1].value;
                            for (const [key, value] of Object.entries(multipleSizesValues)) {
                                multipleSizesValues[key] = (
                                    multipleSizesValues[key] /
                                    1024 /
                                    1024
                                ).toFixed(2);
                            }

                            displayResponse(
                                domElemToRefresh,
                                domElemValues,
                                multipleSizes,
                                multipleSizesValues,
                            );

                            document.querySelectorAll(".animate-spin").forEach(function (el) {
                                el.classList.remove("animate-spin");
                            });
                        } else {
                            rej(data);
                        }
                    })
                    .catch(function (message) {
                        rej(message);
                        console.log(message);
                    });
            });
        });
    });
});
