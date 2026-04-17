var _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };
// <-- Define sort orders -->
var sortOrder = "date";
var sortPlugins = function (a, b) {
    if (
        sortOrder == "downloads" ||
        sortOrder == "revision" ||
        sortOrder == "date"
    )
        return parseInt(a.dataset[sortOrder]) < parseInt(b.dataset[sortOrder])
            ? 1
            : -1;
    else
        return a.dataset[sortOrder].toLowerCase() >
            b.dataset[sortOrder].toLowerCase()
            ? 1
            : -1;
};

_docReady( function () {
    // <-- Set the advanced filters -->

    let betaTestPlugins = document.getElementById("showBetaTestPlugin").hasAttribute("checked");

    // object that remember filters states (initialized later)
    let filters = {};

    // toggle advanced filter's panel
    document.querySelector(".advanced-filter-btn").addEventListener("click", advanced_filter_button_click);
    document.querySelector(".advanced-filter span.icon-cancel").addEventListener("click", advanced_filter_hide);

    function advanced_filter_button_click() {
        if (!document.querySelector(".advanced-filter").classList.contains("advanced-filter-open")) {
            advanced_filter_show();
        } else {
            advanced_filter_hide();
        }
    }

    function advanced_filter_show() {
        document.querySelector(".advanced-filter-btn").classList.add("advanced-filter-open");
        document.querySelector(".advanced-filter").classList.add("advanced-filter-open");
    }

    function advanced_filter_hide() {
        document.querySelector(".advanced-filter-btn").classList.remove("advanced-filter-open");
        document.querySelector(".advanced-filter").classList.remove("advanced-filter-open");
    }

    var orderSelect = document.querySelector('select[name="selectOrder"]');
    if (orderSelect) {
        orderSelect.addEventListener("change", function () {
            sortOrder = this.value;
            var container = document.querySelector(".pluginBox") && document.querySelector(".pluginBox").parentElement;
            if (container) {
                var boxes = Array.from(container.querySelectorAll(".pluginBox"));
                boxes.sort(sortPlugins);
                boxes.forEach(function(el) { container.appendChild(el); });
            }
            fetch("admin.php?plugins_new_order=" + sortOrder);
        });
    }

    document.getElementById("search").addEventListener("input", function () {
        applyFilter("search", this.value.toUpperCase());
        document.getElementById("search").click();
    });

    document.querySelector(".search-cancel").addEventListener("click", function () {
        applyFilter("search", "");
    });

    document.querySelectorAll(".buttonInstall").forEach(function (el) {
        let pluginBox = el.closest(".pluginBox");
        let plugin_name = pluginBox.dataset.name;
        pwgConfirmFollowHref(el, {
            alert_title: str_install_title.replace("%s", plugin_name),
            alert_confirm: str_confirm_msg,
            alert_cancel: str_cancel_msg,
        });
    });

    tippy('.certification', { delay: 0, placement: 'top' });

    document.querySelectorAll(".pluginRating").forEach(function (node) {
        let rating = node.dataset.rating;
        displayStars(node.querySelector(".rating-star-container"), rating);
    });

    // put default values in the select
    let authorNames = [{ value: "", text: "-" }];
    let tagsNames = [{ value: "", text: "-" }];

    // read all plugin boxes to get author and tags
    document.querySelectorAll(".pluginBox").forEach(function (el) {
        let author = el.dataset.author;
        author.split(", ").forEach((name) => {
            if (!authorNames.find((el) => el.value == name)) {
                authorNames.push({ value: name, text: name });
            }
        });

        let tags = el.dataset.tags;
        tags.split(", ").forEach((tag) => {
            if (!tagsNames.find((el) => el.value == tag)) {
                tagsNames.push({ value: tag, text: tag });
            }
        });
    });

    // initialize the TomSelect controls
    var authorEl = document.getElementById("author-filter");
    if (authorEl) {
        var selectizeAuthor = new TomSelect(authorEl, {
            onChange: function (value) {
                applyFilter("author", value);
            },
            plugins: ["remove_button"],
        });
        selectizeAuthor.addOption(authorNames);
    }

    var tagEl = document.getElementById("tag-filter");
    if (tagEl) {
        var selectizeTag = new TomSelect(tagEl, {
            onChange: function (value) {
                applyFilter("tag", value);
            },
            plugins: ["remove_button"],
        });
        selectizeTag.addOption(tagsNames);
    }

        var notationSliderEl = document.querySelector(".notation-filter-slider");
        if (notationSliderEl) {
            noUiSlider.create(notationSliderEl, {
                start: [0],
                range: { min: 0, max: 5 },
                step: 0.5,
                connect: [true, false],
            });
            notationSliderEl.noUiSlider.on("slide", function (values) {
                var val = parseFloat(values[0]);
                updateRatingFilterLabel(val);
                applyFilter("rating", val);
            });
        }

        var revisionSliderEl = document.querySelector(".revision-date-filter-slider");
        if (revisionSliderEl) {
            noUiSlider.create(revisionSliderEl, {
                start: [0],
                range: { min: 0, max: 6 },
                step: 1,
                connect: [true, false],
            });
            revisionSliderEl.noUiSlider.on("slide", function (values) {
                var val = Math.round(parseFloat(values[0]));
                let month;
                [month, _] = value_to_month(val);
                updateRevisionFilterLabel(val);
                applyFilter("revision", month);
            });
        }

        // The certification filter doesn't include incompatible if the beta-test option is not checked
        let minCertification = betaTestPlugins ? -1 : 0;

        var certificationSliderEl = document.querySelector(".certification-filter-slider");
        if (certificationSliderEl) {
            noUiSlider.create(certificationSliderEl, {
                start: [minCertification],
                range: { min: minCertification, max: 3 },
                step: 1,
                connect: [true, false],
            });
            certificationSliderEl.noUiSlider.on("slide", function (values) {
                var val = Math.round(parseFloat(values[0]));
                updateCertificationFilterLabel(val);
                applyFilter("certification", val);
            });
        }

        updateRatingFilterLabel(0);
        updateCertificationFilterLabel(minCertification);
        updateRevisionFilterLabel(0);

        var showBetaEl = document.getElementById("showBetaTestPlugin");
        if (showBetaEl) {
            showBetaEl.addEventListener("change", function (e) {
                document.querySelectorAll(".beta-test-plugin-switch .slider").forEach(function(el) {
                    el.classList.add("loading");
                });

                let queryParams = new URLSearchParams(window.location.search);
                queryParams.set("beta-test", e.currentTarget.checked.toString());
                history.replaceState(null, null, "?" + queryParams.toString());
                window.location.reload(true);
            });
        }

    // All the slider values and it's corresponding month's number and label
    function value_to_month(val) {
        switch (val) {
            case 6:
                return [1, str_x_month.replace("%d", 1)];
                break;
            case 5:
                return [3, str_x_months.replace("%d", 3)];
                break;
            case 4:
                return [6, str_x_months.replace("%d", 6)];
                break;
            case 3:
                return [12, str_x_year.replace("%d", 1)];
                break;
            case 2:
                return [24, str_x_years.replace("%d", 2)];
                break;
            case 1:
                return [60, str_x_years.replace("%d", 5)];
                break;
            default:
                return [Number.MAX_SAFE_INTEGER, str_from_beginning];
                break;
        }
    }

    // Difference between two dates, in months
    function monthDiff(d1, d2) {
        var months;
        months = (d2.getFullYear() - d1.getFullYear()) * 12;
        months -= d1.getMonth();
        months += d2.getMonth();
        return months <= 0 ? 0 : months;
    }

    function displayStars(element, rating) {
        element.querySelectorAll("span").forEach(function (span) {
            span.classList.add("icon-star-empty");
            var i = span.querySelector("i");
            if (i) i.className = "";
        });

        rating = Math.round(rating * 2);

        if (rating % 2 == 1) {
            var starEl = element.querySelector("span[data-star='" + (rating - 1) / 2 + "'] i");
            if (starEl) starEl.classList.add("icon-star-half");
            rating -= 1;
        }

        while (rating > 0) {
            rating -= 2;
            var starEl = element.querySelector("span[data-star='" + rating / 2 + "'] i");
            if (starEl) starEl.classList.add("icon-star");
            var spanEl = element.querySelector("span[data-star='" + rating / 2 + "']");
            if (spanEl) spanEl.classList.remove("icon-star-empty");
        }
    }

    // Updates labels when input change

    function updateRatingFilterLabel(value) {
        displayStars(
            document.querySelector(".advanced-filter-rating .rating-star-container"),
            value,
        );
    }

    function updateCertificationFilterLabel(value) {
        let certifNode = document.querySelector(".advanced-filter-certification .certification");
        certifNode.setAttribute("data-certification", value);
        certifNode.setAttribute("title", strs_certification[String(value)]);
        tippy(certifNode, { delay: 0, placement: 'top' });
    }

    function updateRevisionFilterLabel(val) {
        let label;
        [_, label] = value_to_month(val);
        document.querySelector(".revision-date").innerHTML = label;
    }

    // <-- Apply advanced filters -->

    // object that remember filters states
    filters = {
        search: document.getElementById("search").value,
        author: "",
        tag: "",
        rating: notationSliderEl && notationSliderEl.noUiSlider
            ? parseFloat(notationSliderEl.noUiSlider.get())
            : 0,
        certification: certificationSliderEl && certificationSliderEl.noUiSlider
            ? Math.round(parseFloat(certificationSliderEl.noUiSlider.get()))
            : betaTestPlugins ? -1 : 0,
        revision: value_to_month(
            certificationSliderEl && certificationSliderEl.noUiSlider
                ? Math.round(parseFloat(certificationSliderEl.noUiSlider.get()))
                : betaTestPlugins ? -1 : 0,
        )[0],
    };

    if (authorEl && authorEl.tomselect) authorEl.tomselect.setValue("");
    if (tagEl && tagEl.tomselect) tagEl.tomselect.setValue("");

    function applyFilter(changed, value) {
        filters[changed] = value;

        sort((pluginBox) => {
            let pluginRating =
                (pluginBox.querySelector(".pluginRating")?.dataset.rating || 0);
            let pluginCertification = pluginBox
                .querySelector(".certification")?.dataset.certification;
            let pluginAuthors = pluginBox.dataset.author.split(", ");
            let pluginName = pluginBox.dataset.name.toUpperCase();
            let pluginTags = pluginBox.dataset.tags.split(", ");
            let pluginRevisionOld = monthDiff(
                new Date(pluginBox.dataset.revision * 1000),
                new Date(),
            ); // number of months between the last revision date and now

            return (
                pluginRating >= filters.rating &&
                pluginCertification >= filters.certification &&
                (filters.search === "" ||
                    pluginName.indexOf(filters.search) != -1) &&
                (filters.author === "" ||
                    pluginAuthors.includes(filters.author)) &&
                (filters.tag === "" || pluginTags.includes(filters.tag)) &&
                pluginRevisionOld <= filters["revision"]
            );
        });
    }

    // Display or not plugin with a function handler
    function sort(sortFunction) {
        document.querySelectorAll(".pluginBox").forEach(function (el) {
            if (sortFunction(el)) {
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });
    }

    function clearSort() {
        document.querySelectorAll(".pluginBox").forEach(function (el) {
            el.style.display = '';
        });
    }

    // Crop the names of plugins if there are too long
    document.querySelectorAll(".pluginName span").forEach(function (el) {
        if (el.innerHTML.length > 30) {
            el.innerHTML = el.innerHTML.slice(0, 30) + "...";
        }
    });
});
