function fitExtensions() {
    document.querySelectorAll(".format-card-ext span").forEach(function (node) {
        var size = Math.min((180 * 1) / node.innerHTML.length, 45);
        node.style.fontSize = size + "px";
    });
}

fitExtensions();

document.querySelectorAll(".format-card").forEach(function (node) {
    var button = node.querySelector(".format-delete");
    if (button) {
        button.addEventListener("click", function () {
            var extSpan = node.querySelector(".format-card-ext span");
            pwgConfirm({
                title: str_confirm_delete_format.replace(
                    "%s",
                    extSpan ? extSpan.innerHTML : "",
                ),
                buttons: {
                    confirm: {
                        text: str_confirm_msg,
                        btnClass: "btn-red",
                        action: function () {
                            deleteFormat(node);
                        },
                    },
                    cancel: {
                        text: str_cancel_msg,
                    },
                },
            });
        });
    }
});

function deleteFormat(card) {
    var deleteIcon = card.querySelector(".format-delete i");
    if (deleteIcon) deleteIcon.setAttribute("class", "icon-spin6 animate-spin");
    fetch("ws.php?format=json&method=pwg.images.formats.delete", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            pwg_token: pwg_token,
            format_id: card.dataset.id,
        }),
    })
        .then(function (response) { return response.json(); })
        .then(function () {
            card.style.display = 'none';
            card.parentNode && card.parentNode.removeChild(card);
            if (document.querySelectorAll(".format-card").length == 0) {
                var noFormats = document.querySelector(".no-formats");
                if (noFormats) noFormats.style.display = '';
            }
        })
        .catch(function (message) {
            console.log(message);
        });
}
