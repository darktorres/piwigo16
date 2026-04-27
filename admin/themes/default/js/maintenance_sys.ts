const color_icons = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];

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
    const new_line = $('#body_example').clone();
    const line_details_example = $('#line_details_example').clone();
    const initial_user = line.username.charAt(0).toUpperCase();

    new_line.attr('id', String(line.id));
    if (line.major_infos) new_line.addClass('major-infos');

    new_line.find('.icon_object').addClass(line.object_icon);
    new_line.find('.text_object').text(line.object).attr('title', line.object);
    new_line.find('.color_action').addClass(line.action_color);
    new_line.find('.icon_action').addClass(line.action_icon);
    new_line.find('.text_action').text(line.action).attr('title', line.action);

    if (line.username === 'System') {
        new_line.find('.icon_user').addClass('icon-robot-head');
    } else {
        new_line.find('.icon_user').addClass(color_icons[line.user_id % 5]).html(initial_user);
    }
    new_line.find('.text_username').text(line.username).attr('title', line.username);
    new_line.find('.text_date').text(line.date).attr('title', line.date + ' ' + line.hour);
    new_line.find('.text_hour').text(line.hour);

    switch (line.detail.type) {
        case 'error':
        case 'version':
        case 'maintenance_action': {
            const d = line_details_example.clone().removeAttr('id');
            d.find('.icon_details').addClass(line.detail.icon ?? '');
            d.find('.text_details').text(String(line.detail.text ?? '')).attr('title', String(line.detail.text ?? ''));
            new_line.find('.tab-body-details').append(d);
            break;
        }
        case 'db_fs_version':
        case 'config_section':
            Object.keys(line.detail)
                .filter((key) => key !== 'type')
                .forEach((key) => {
                    const detail = line.detail[key] as { icon: string; text: string };
                    const d = line_details_example.clone().removeAttr('id');
                    d.find('.icon_details').addClass(detail.icon);
                    d.find('.text_details').text(detail.text).attr('title', detail.text);
                    new_line.find('.tab-body-details').append(d);
                });
            break;
        case 'from_to': {
            const items = line.detail as unknown as Array<{ icon: string; text: string }>;
            const from = line_details_example.clone().removeAttr('id');
            from.find('.icon_details').addClass(items[0].icon);
            from.find('.text_details').text(items[0].text).attr('title', items[0].text);
            new_line.find('.tab-body-details').append(from);
            new_line.find('.tab-body-details').append('<span class="icon-right">  </span>');
            const to = line_details_example.clone().removeAttr('id');
            to.find('.icon_details').addClass(items[1].icon);
            to.find('.text_details').text(items[1].text).attr('title', items[1].text);
            new_line.find('.tab-body-details').append(to);
            break;
        }
        default: break;
    }

    $('#tab-body-content').append(new_line);
}

function get_system_activities(): void {
    $.ajax({
        url: window.location.href,
        type: 'GET',
        data: { method: 'pwg.activity_sys.getList' },
        dataType: 'json',
        success: (response: { data: ActivityLine[] }) => {
            $('.loading').hide();
            response.data.forEach((line) => line_constructor(line));
        },
        error: (e) => { console.log(e); },
    });
}

$(document).ready(function () { get_system_activities(); });

export {};
