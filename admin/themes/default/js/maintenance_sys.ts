interface ActivityLine {
    id: string | number;
    username: string;
    user_id: number;
    major_infos?: boolean;
    object_icon: string;
    object: string;
    action_color: string;
    action_icon: string;
    action: string;
    date: string;
    hour: string;
    detail: {
        type: string;
        icon?: string;
        text?: string;
        [key: string]: unknown;
    };
}

function line_constructor(line: ActivityLine): void {
    const newLine = document.getElementById('body_example')!.cloneNode(true) as HTMLElement;
    const lineDetailsExample = document
        .getElementById('line_details_example')!
        .cloneNode(true) as HTMLElement;
    const initial_user = line.username.charAt(0).toUpperCase();

    newLine.id = String(line.id);
    if (line.major_infos) newLine.classList.add('major-infos');

    const qs = (sel: string): HTMLElement => newLine.querySelector<HTMLElement>(sel)!;

    qs('.icon_object').classList.add(line.object_icon);
    qs('.text_object').textContent = line.object;
    qs('.text_object').setAttribute('title', line.object);
    qs('.color_action').classList.add(line.action_color);
    qs('.icon_action').classList.add(line.action_icon);
    qs('.text_action').textContent = line.action;
    qs('.text_action').setAttribute('title', line.action);

    if (line.username === 'System') {
        qs('.icon_user').classList.add('icon-robot-head');
    } else {
        const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
        qs('.icon_user').classList.add(colors[line.user_id % 5]);
        qs('.icon_user').innerHTML = initial_user;
    }
    qs('.text_username').textContent = line.username;
    qs('.text_username').setAttribute('title', line.username);
    qs('.text_date').textContent = line.date;
    qs('.text_date').setAttribute('title', line.date + ' ' + line.hour);
    qs('.text_hour').textContent = line.hour;

    const tabDetails = qs('.tab-body-details');

    const cloneDetail = (): HTMLElement => {
        const d = lineDetailsExample.cloneNode(true) as HTMLElement;
        d.removeAttribute('id');
        return d;
    };

    switch (line.detail.type) {
        case 'error':
        case 'version':
        case 'maintenance_action': {
            const d = cloneDetail();
            d.querySelector<HTMLElement>('.icon_details')?.classList.add(line.detail.icon ?? '');
            const txt = d.querySelector<HTMLElement>('.text_details');
            if (txt) {
                txt.textContent = String(line.detail.text ?? '');
                txt.setAttribute('title', String(line.detail.text ?? ''));
            }
            tabDetails.append(d);
            break;
        }
        case 'db_fs_version':
        case 'config_section':
            Object.keys(line.detail)
                .filter((key) => key !== 'type')
                .forEach((key) => {
                    const detail = line.detail[key] as { icon: string; text: string };
                    const d = cloneDetail();
                    d.querySelector<HTMLElement>('.icon_details')?.classList.add(detail.icon);
                    const txt = d.querySelector<HTMLElement>('.text_details');
                    if (txt) {
                        txt.textContent = detail.text;
                        txt.setAttribute('title', detail.text);
                    }
                    tabDetails.append(d);
                });
            break;
        case 'from_to': {
            const items = line.detail as unknown as Array<{ icon: string; text: string }>;
            const from = cloneDetail();
            from.querySelector<HTMLElement>('.icon_details')?.classList.add(items[0].icon);
            const fromTxt = from.querySelector<HTMLElement>('.text_details');
            if (fromTxt) {
                fromTxt.textContent = items[0].text;
                fromTxt.setAttribute('title', items[0].text);
            }
            tabDetails.append(from);
            const arrow = document.createElement('span');
            arrow.className = 'icon-right';
            arrow.textContent = '  ';
            tabDetails.append(arrow);
            const to = cloneDetail();
            to.querySelector<HTMLElement>('.icon_details')?.classList.add(items[1].icon);
            const toTxt = to.querySelector<HTMLElement>('.text_details');
            if (toTxt) {
                toTxt.textContent = items[1].text;
                toTxt.setAttribute('title', items[1].text);
            }
            tabDetails.append(to);
            break;
        }
        default:
            break;
    }

    document.getElementById('tab-body-content')!.append(newLine);
}

function get_system_activities(): void {
    const url = new URL(window.location.href);
    url.searchParams.set('method', 'pwg.activity_sys.getList');
    fetch(url.toString())
        .then((r) => r.json())
        .then((response: { data: ActivityLine[] }) => {
            document.querySelectorAll<HTMLElement>('.loading').forEach((el) => {
                el.style.display = 'none';
            });
            response.data.forEach((line) => line_constructor(line));
        })
        .catch((e) => console.log(e));
}

document.addEventListener('DOMContentLoaded', () => {
    get_system_activities();
});

export {};
