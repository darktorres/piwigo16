interface ToasterInfo {
    text: string;
    icon: 'success' | 'error';
    time?: number;
}

function pwgToaster(info: ToasterInfo): void {
    if (info.text === '') {
        console.error('set info.text');
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

(window as Window & { pwgToaster: typeof pwgToaster }).pwgToaster = pwgToaster;

export {};
