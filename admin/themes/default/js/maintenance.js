import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

export function init(cfg) {
    const confirm_msg = cfg.confirm_msg || 'Yes, I am sure';
    const cancel_msg = cfg.cancel_msg || 'No, I have changed my mind';
    const str_gallery_tip = cfg.str_gallery_tip || 'A locked gallery is only visible to administrators';
    const str_lock_unlock_title = cfg.str_lock_unlock_title || 'Are you sure?';
    const str_purge_detail = cfg.str_purge_detail || 'Purge history detail';
    const str_purge_summary = cfg.str_purge_summary || 'Purge history summary';
    const str_purge_search = cfg.str_purge_search || 'Purge search history';
    const str_delete_all_sizes = cfg.str_delete_all_sizes || 'Are you sure you want to delete all sizes?';
    const unit_MB = cfg.unit_MB || '%s MB';
    const no_time_elapsed = cfg.no_time_elapsed || 'right now';
    const pwg_token = cfg.pwg_token || '';

    function displayResponse(domElem, values, mDivs, mValues) {
        for (let index = 0; index < domElem.length; index++) {
            domElem[index].innerHTML = unit_MB.replace("%s", values[index]);
        }

        for (let index = 0; index < mDivs.length; index++) {
            const mDivName = mDivs[index].getAttribute("name");
            mDivs[index].title = unit_MB.replace("%s", mValues[mDivName]);
        }

        var cacheLastCalc = document.querySelector(".cache-lastCalculated-value");
        if (cacheLastCalc) cacheLastCalc.innerHTML = no_time_elapsed;
    }
    document.querySelectorAll(".lock-gallery-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: str_lock_unlock_title,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg,
        alert_content: str_gallery_tip
      });
    });

    document.querySelectorAll(".purge-history-detail-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: str_purge_detail,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });

    document.querySelectorAll(".purge-history-summary-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: str_purge_summary,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });

    document.querySelectorAll(".purge-search-history-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: str_purge_search,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });

    document.querySelectorAll(".delete-all-sizes-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: str_delete_all_sizes,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });

    document.querySelectorAll(".delete-size-check").forEach(function(el) {
      el.addEventListener('click', function() {
        if (this.getAttribute('data-selected') == '1') {
          this.setAttribute('data-selected', '0');
          let icon = this.querySelector("i");
          if (icon) icon.style.display = 'none';
        } else {
          this.setAttribute('data-selected', '1');
          let icon = this.querySelector("i");
          if (icon) icon.style.display = '';
        }
        this.dispatchEvent(new Event('change', {bubbles: true}));
      });
    });

    var firstDeleteSizeCheck = document.querySelector(".delete-size-check");
    if (firstDeleteSizeCheck) {
      firstDeleteSizeCheck.addEventListener('change', function() {
        var allChecks = document.querySelectorAll(".delete-size-check");
        if (this.getAttribute('data-selected') == '1') {
          allChecks.forEach(function(el) {
            el.style.display = 'none';
            el.setAttribute("data-selected", "1");
          });
          this.style.display = '';
        } else {
          allChecks.forEach(function(el) {
            el.style.display = '';
            el.setAttribute("data-selected", "0");
          });
        }
      });
    }

    const delete_deriv_URL = "admin.php?page=maintenance&action=derivatives&";
    document.querySelectorAll(".delete-size-check").forEach(function(el) {
      el.addEventListener('change', function() {
        var delete_deriv_with_token = delete_deriv_URL + "pwg_token=" + pwg_token + "&";
        var types_str = '';
        var selected = [];
        document.querySelectorAll(".delete-size-check").forEach(function(check) {
          if (check.getAttribute("data-selected") == '1') {
            selected.push(check.getAttribute("name"));
          }
        });
        var deleteLink = document.querySelector(".delete-sizes");
        if (selected.length == 0) {
          if (deleteLink) deleteLink.setAttribute("href", "");
        } else {
          if (selected[0] == "all") {
            types_str = "all";
          } else {
            types_str = selected.join("_");
          }
          if (deleteLink) deleteLink.setAttribute("href", delete_deriv_with_token + "type=" + types_str);
        }
      });
    });

    var deleteSizesLink = document.querySelector(".delete-sizes");
    if (deleteSizesLink) deleteSizesLink.style.display = 'none';

    document.querySelectorAll(".delete-size-check").forEach(function(el) {
      el.addEventListener('click', function() {
        var displayDeleteSizes = false;
        document.querySelectorAll(".delete-size-check").forEach(function(check) {
          if (check.getAttribute("data-selected") == 1) {
            displayDeleteSizes = true;
          }
        });
        var deleteSizes = document.querySelector(".delete-sizes");
        if (deleteSizes) deleteSizes.style.display = displayDeleteSizes ? '' : 'none';
      });
    });

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
                        const data = JSON.parse(raw);
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
}

initModule(init);
