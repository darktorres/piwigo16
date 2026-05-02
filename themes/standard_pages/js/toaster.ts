interface ToasterInfo {
    text: string;
    icon: 'success' | 'error';
    time?: number;
}

function pwgToaster(info: ToasterInfo): void {
    if (!info.text || !info.icon) {
        console.log('set info.text or info.icon');
        return;
    }
    if (typeof info.text !== 'string') {
        console.log('info.text is not a string');
        return;
    }
    if (info.icon !== 'success' && info.icon !== 'error') {
        console.log('info.icon must be success or error');
        return;
    }

    const template = document.getElementById('toast_template')!.cloneNode(true) as HTMLElement;
    template.querySelector<HTMLElement>('.toast_text')!.innerHTML = info.text;
    template
        .querySelector<HTMLElement>('.toast_icon')!
        .classList.add(info.icon === 'success' ? 'icon-ok' : 'icon-cancel');
    template.classList.add(info.icon === 'success' ? 'success' : 'error');
    template.classList.remove('template-pwg-toaster');
    document.getElementById('pwg_toaster')!.appendChild(template);

    const time = info.time ?? 3600;
    setTimeout(() => {
        template.style.transition = 'opacity 0.4s';
        template.style.opacity = '0';
        setTimeout(() => template.remove(), 400);
    }, time);
}

(window as any).pwgToaster = pwgToaster;

export {};
