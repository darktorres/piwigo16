export function pwgAddAlbum(btn, options) {
    options = options || {};

    var popup = document.getElementById("addAlbumForm");
    var albumParentEl = popup ? popup.querySelector('[name="category_parent"]') : null;
    var targetEl = btn ? document.querySelector('[name="' + btn.dataset.addAlbum + '"]') : null;
    var cache = targetEl ? targetEl._pwgCache : null;

    if (targetEl && !targetEl.tomselect) {
        throw new Error("pwgAddAlbum: target must use TomSelect");
    }
    if (!cache) {
        throw new Error("pwgAddAlbum: missing categories cache");
    }

    function init() {
        popup._init = true;

        cache.selectize(albumParentEl, {
            default: 0,
            filter: function (categories) {
                categories.push({
                    id: 0,
                    fullname: "------------",
                    global_rank: 0,
                });

                if (options.filter) {
                    categories = options.filter.call(this, categories);
                }

                return categories;
            },
        });

        popup.querySelector("form").addEventListener("submit", function (e) {
            e.preventDefault();

            var parent_id = albumParentEl.tomselect ? albumParentEl.tomselect.getValue() : albumParentEl.value;
            var name = popup.querySelector("[name=category_name]").value;

            if (!name) {
                document.getElementById("categoryNameError").style.visibility = "visible";
                return;
            }
            document.getElementById("categoryNameError").style.visibility = "hidden";

            var formData = new FormData();
            formData.append("method", "pwg.categories.add");
            formData.append("parent", parent_id);
            formData.append("name", name);

            document.getElementById("albumCreationLoading").style.display = "inline-block";
            document.querySelectorAll(".albumCreationButton").forEach(function (el) { el.style.display = "none"; });

            fetch("ws.php?format=json", { method: "POST", body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    document.getElementById("albumCreationLoading").style.display = "none";
                    document.querySelectorAll(".albumCreationButton").forEach(function (el) { el.style.display = ""; });
                    popup.close();

                    var newAlbum = {
                        id: data.result.id,
                        name: name,
                        fullname: name,
                        global_rank: "0",
                        dir: null,
                        nb_images: 0,
                        pos: 0,
                    };

                    var parentSelectize = albumParentEl.tomselect;

                    if (parent_id != 0) {
                        var parent = parentSelectize.options[parent_id];
                        newAlbum.fullname = parent.fullname + " / " + newAlbum.fullname;
                        newAlbum.global_rank = parent.global_rank + ".1";
                        newAlbum.pos = parent.pos + 1;
                    }

                    var targetSelectize = targetEl.tomselect;
                    targetSelectize.addOption(newAlbum);
                    targetSelectize.setValue(newAlbum.id);

                    parentSelectize.addOption(newAlbum);

                    if (options.afterSelect) {
                        options.afterSelect();
                    }
                })
                .catch(function (err) {
                    document.getElementById("albumCreationLoading").style.display = "none";
                    alert(err.message || err);
                });
        });
    }

    btn.addEventListener("click", function (e) {
        e.preventDefault();

        if (!popup._init) {
            init();
        }

        document.getElementById("categoryNameError").style.visibility = "hidden";
        popup.querySelector("[name=category_name]").value = "";
        popup.querySelector("[name=category_name]").focus();
        if (albumParentEl.tomselect) {
            albumParentEl.tomselect.setValue(targetEl.value || 0);
        }

        popup.showModal();
    });

    return btn;
}
