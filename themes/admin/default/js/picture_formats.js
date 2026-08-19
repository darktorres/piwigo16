var pwg_token = pwg_getPageData('csrf_token');
var str_confirm_delete_format = pwg_getPageString('Delete %s format ?');
var str_confirm_msg = pwg_getPageString('Yes, I am sure');
var str_cancel_msg = pwg_getPageString('No, I have changed my mind');

function fitExtensions() {
    $(".format-card-ext span").each((i, node) => {
        let size = Math.min(180 * 1/node.innerHTML.length, 45) 
        node.setAttribute('style', `font-size:${size}px`)
    })
}

fitExtensions()

$('.format-card').each((i, node) => {
    let card = $(node)
    let button = card.find(".format-delete")
    button.click(() => {
        $.confirm({
            title: str_confirm_delete_format.replace("%s",card.find('.format-card-ext span').html()),
            content: "",
            buttons: {
              confirm: {
                text: str_confirm_msg,
                btnClass: 'btn-red',
                action: function () {
                    deleteFormat(card)
                },
              },
              cancel: {
                text: str_cancel_msg
              }
            },
            ...jConfirm_confirm_options
          })
        
    })
})

function deleteFormat(card) {
    card.find('.format-delete i').attr("class", "icon-spin6 animate-spin")
    $.ajax({
        url: "api/v1/images/formats/actions/delete",
        type: "POST",
        contentType: "application/json",
        headers: {'X-CSRF-Token': pwg_token},
        data: JSON.stringify({
            formatIds: [Number(card.data("id"))],
        }),
        success: function (raw_data) {
            card.fadeOut("slow", () => {
                card.remove();
                if ($('.format-card').length == 0)
                    $('.no-formats').show()
            })
        },
        error: function(message) {
            console.log(message);
        }
    })
}