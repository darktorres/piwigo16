declare var str_confirm_delete_format: string;
declare var str_confirm_msg: string;
declare var str_cancel_msg: string;

function fitExtensions(): void {
    $('.format-card-ext span').each((_i, node) => {
        const el = node as HTMLElement;
        const size = Math.min(180 * (1 / el.innerHTML.length), 45);
        el.setAttribute('style', `font-size:${size}px`);
    });
}

fitExtensions();

$('.format-card').each((_i, node) => {
    const card = $(node);
    const button = card.find('.format-delete');
    button.on('click', () => {
        $.confirm({
            title: str_confirm_delete_format.replace('%s', card.find('.format-card-ext span').html() ?? ''),
            content: '',
            buttons: {
                confirm: {
                    text: str_confirm_msg,
                    btnClass: 'btn-red',
                    action() { deleteFormat(card); },
                },
                cancel: { text: str_cancel_msg },
            },
            ...jConfirm_confirm_options,
        });
    });
});

function deleteFormat(card: JQuery): void {
    card.find('.format-delete i').attr('class', 'icon-spin6 animate-spin');
    $.ajax({
        url: 'ws.php?format=json&method=pwg.images.formats.delete',
        type: 'POST',
        data: { pwg_token, format_id: card.data('id') },
        success() {
            card.fadeOut('slow', () => {
                card.remove();
                if ($('.format-card').length === 0) $('.no-formats').show();
            });
        },
        error(message) { console.log(message); },
    });
}

export {};
