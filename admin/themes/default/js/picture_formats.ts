import { initModule } from './moduleInit.js';
import { pwgConfirm } from './pwgConfirm.js';

interface PictureFormatsConfig {
    pwg_token?: string;
    str_confirm_delete_format?: string;
    str_confirm_msg?: string;
    str_cancel_msg?: string;
}

export function init(cfg: PictureFormatsConfig): void {
    const { pwg_token = '', str_confirm_delete_format = '', str_confirm_msg = 'Yes', str_cancel_msg = 'Cancel' } = cfg;

    function fitExtensions(): void {
        document.querySelectorAll<HTMLElement>(".format-card-ext span").forEach(function (node) {
            const size = Math.min((180 * 1) / node.innerHTML.length, 45);
            node.style.fontSize = size + "px";
        });
    }
    fitExtensions();

    document.querySelectorAll<HTMLElement>(".format-card").forEach(function (node) {
        const button = node.querySelector<HTMLElement>(".format-delete");
        if (!button) return;
        button.addEventListener("click", function () {
            const extSpan = node.querySelector<HTMLElement>(".format-card-ext span");
            pwgConfirm({
                title: str_confirm_delete_format.replace("%s", extSpan?.innerHTML ?? ''),
                buttons: {
                    confirm: { text: str_confirm_msg, btnClass: "btn-red", action: () => deleteFormat(node) },
                    cancel: { text: str_cancel_msg },
                },
            });
        });
    });

    function deleteFormat(card: HTMLElement): void {
        const deleteIcon = card.querySelector<HTMLElement>(".format-delete i");
        if (deleteIcon) deleteIcon.setAttribute("class", "icon-spin6 animate-spin");
        fetch("ws.php?format=json&method=pwg.images.formats.delete", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ pwg_token, format_id: card.dataset['id'] ?? '' }),
        })
            .then(r => r.json())
            .then(function () {
                card.style.display = 'none';
                card.parentNode?.removeChild(card);
                if (document.querySelectorAll(".format-card").length === 0) {
                    const noFormats = document.querySelector<HTMLElement>(".no-formats");
                    if (noFormats) noFormats.style.display = '';
                }
            })
            .catch(console.log);
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
