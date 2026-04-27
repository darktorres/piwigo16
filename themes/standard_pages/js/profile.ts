let PWG_TOKEN: string;

$(function () {
    PWG_TOKEN = String($('#pwg_token').val() ?? '');

    $('.profile-section .display-section').on('click', function () {
        const display = String($(this).data('display'));
        const selector = $(`#${display}`);
        const element = selector.get(0) as HTMLElement;
        const arrow = $(this).find('.display-btn');

        if (selector.hasClass('open')) {
            element.style.maxHeight = element.scrollHeight + 'px';
            void element.offsetHeight;
            element.style.maxHeight = '1px';
            selector.removeClass('open');
            arrow.addClass('close');
        } else {
            selector.addClass('open');
            resetSection(display);
            arrow.removeClass('close');
        }
    });

    setTimeout(() => {
        $('#account-section .display-section').trigger('click');
    }, 100);

    $('#save_account').on('click', function () {
        const mail = $('#email').val();
        if (!mail || mail === '') {
            $('#email_error').show();
            return;
        }
        setInfos({ email: mail });
    });

    if (canUpdatePreferences) {
        $('#save_preferences').on('click', function () {
            const values: Record<string, unknown> = {
                nb_image_page: $('#nb_image_page').val(),
                theme: $('select[name="theme"]').val(),
                language: $('select[name="language"]').val(),
                recent_period: $('#recent_period').val(),
                expand: $('#opt_album').is(':checked'),
                show_nb_comments: $('#opt_comment').is(':checked'),
                show_nb_hits: $('#opt_hits').is(':checked'),
            };
            if (!values.nb_image_page) { $('#error_nb_image').show(); return; }
            if (!values.recent_period) { $('#error_period').show(); return; }
            setInfos(values);
        });

        $('#reset_preferences').on('click', function () {
            const u = user as any;
            $('input[name="nb_image_page"]').val(u.nb_image_page);
            $('select[name="theme"]').val(u.theme);
            $('select[name="language"]').val(u.language);
            $('input[name="recent_period"]').val(u.recent_period);
            $('#opt_album').prop('checked', u.opt_album);
            $('#opt_comment').prop('checked', u.opt_comment);
            $('#opt_hits').prop('checked', u.opt_hits);
        });

        $('#default_preferences').on('click', function () {
            const d = preferencesDefaultValues as any;
            $('input[name="nb_image_page"]').val(d.nb_image_page);
            $('input[name="recent_period"]').val(d.recent_period);
            $('#opt_album').prop('checked', d.opt_album);
            $('#opt_comment').prop('checked', d.opt_comment);
            $('#opt_hits').prop('checked', d.opt_hits);
        });
    }

    if (canUpdatePassword) {
        $('#save_password').on('click', function () {
            const passwords: Record<string, unknown> = {
                password: $('#password').val(),
                new_password: $('#password_new').val(),
                conf_new_password: $('#password_conf').val(),
            };
            if (!passwords.password || !passwords.new_password || !passwords.conf_new_password) {
                $('#password-section input').each((_, element) => {
                    const el = $(element);
                    if (!el.val()) el.parent().siblings().show();
                });
                return;
            }
            setInfos(passwords);
            $('#password-section input').val('');
        });
    }

    standardSaveSelector.forEach((selectorStr, i) => {
        $(selectorStr).on('click', function () {
            const values: Record<string, unknown> = {};
            $(`#${i}-section`).find('input, textarea, select').each((_, element) => {
                const el = $(element as HTMLInputElement);
                const inputName = el.attr('name');
                if (inputName) values[inputName] = el.val();
            });
            setInfos(values);
        });
    });

    if (!can_manage_api) {
        $('.can-manage').hide();
        $('#cant_manage_api').show();
        return;
    }

    $('#new_apikey').on('click', openApiModal);
    $('#close_api_modal, #cancel_apikey').on('click', closeApiModal);
    $('#close_api_modal_edit').on('click', closeApiEditModal);
    $('#close_api_modal_revoke, #cancel_api_revoke').on('click', closeApiRevokeModal);

    $('#show_expired_list').on('click', function () {
        const api_list_expired = $('#api_key_list_expired');
        const isOpen = $(this).data('show') as boolean;
        if (!isOpen) {
            (api_list_expired.get(0) as HTMLElement).style.maxHeight = 'max-content';
            $(this).text(str_hide_expired);
        } else {
            (api_list_expired.get(0) as HTMLElement).style.maxHeight = '0';
            $(this).text(str_show_expired);
        }
        $(this).data('show', !isOpen);
        resetSection('apikey-display', false, true);
    });

    $(window).on('keydown', function (e) {
        if ($('#api_modal').is(':visible') && e.key === 'Escape') closeApiModal();
        if ($('#api_modal_edit').is(':visible') && e.key === 'Escape') closeApiEditModal();
        if ($('#api_modal_revoke').is(':visible') && e.key === 'Escape') closeApiRevokeModal();
    });

    $('select[name="api_expiration"]').on('change', function () {
        const custom_date = $('#api_custom_date');
        if ($(this).val() === 'custom') custom_date.css('display', 'flex');
        else custom_date.css('display', 'none');
        $('#error_api_key_date').hide();
    });

    $('#api_expiration_date').on('change', function () { $('#error_api_key_date').hide(); });

    getAllApiKeys();
});

function setInfos(
    params: Record<string, unknown>,
    method = 'pwg.users.setMyInfo',
    callback: ((res: unknown) => void) | null = null,
    errCallback: ((err: unknown) => void) | null = null
): void {
    const all_params = { ...params, pwg_token: PWG_TOKEN };
    $.ajax({
        url: `ws.php?format=json&method=${method}`,
        type: 'POST',
        dataType: 'json',
        data: all_params as any,
        success: (data: any) => {
            if (data.stat === 'ok') {
                (window as any).user = { ...user, ...params };
                if (typeof callback === 'function') { callback(data.result); return; }
                pwgToaster({ text: data.result, icon: 'success' });
            } else if (data.stat === 'fail') {
                pwgToaster({ text: data.message, icon: 'error' });
            } else {
                pwgToaster({ text: str_handle_error, icon: 'error' });
            }
            if (typeof errCallback === 'function') errCallback(data);
        },
        error: (e: any) => {
            pwgToaster({ text: e.responseJSON?.message ?? str_handle_error, icon: 'error' });
            if (typeof errCallback === 'function') errCallback(e);
        },
    });
}

function getAllApiKeys(reset = false): void {
    $.ajax({
        url: 'ws.php?format=json&method=pwg.users.api_key.get',
        type: 'POST',
        dataType: 'json',
        data: { pwg_token: PWG_TOKEN },
        success: (res: any) => {
            if (res.stat === 'ok' && typeof res.result !== 'string' && res.result !== false) {
                AddApiLine(res.result, reset);
            }
        },
        error: (e: any) => {
            pwgToaster({ text: e.responseJSON?.message ?? str_handle_error + 'getAllApiKeys', icon: 'error' });
        },
    });
}

function AddApiLine(lines: any[], reset: boolean): void {
    const api_list = $('#api_key_list');
    const api_list_expired = $('#api_key_list_expired');

    $('#api_key_list .api-tab-line:not(.template-api), #api_key_list .api-tab-collapse:not(.template-api)').remove();
    $('#api_key_list_expired .api-tab-line:not(.template-api), #api_key_list_expired .api-tab-collapse:not(.template-api)').remove();

    lines.forEach((line) => {
        const api_line = $('#api_line').clone();
        const api_collapse = $('#api_collapse').clone();
        const tmp_id = String(line.auth_key).slice(24, 34);

        api_line.removeClass('template-api').addClass('api-tab').attr('id', `api_${tmp_id}`);
        api_line.find('.icon-collapse').data('api', tmp_id);
        api_line.find('.api_name').text(line.apikey_name).attr('title', line.apikey_name);
        api_line.find('.api_creation').text(line.created_on_format);
        api_line.find('.api_last_use').text(line.last_used_on_since).attr('title', line.last_used_on_since);
        api_line.find('.api_expiration').text(line.expiration);
        api_line.find('.api-icon-action').attr('data-api', `api_${tmp_id}`).attr('data-pkid', line.auth_key);

        api_collapse.attr('id', `api_collapse_${tmp_id}`).removeClass('template-api');
        api_collapse.find('.api_key').text(line.auth_key);
        api_collapse.find('.icon-clone').attr({ 'data-copy': line.auth_key, 'data-success': `api_copy_success_${tmp_id}` });
        api_collapse.find('.api-copy').attr('id', `api_copy_success_${tmp_id}`);

        if (!line.revoked_on && !line.is_expired) {
            api_list.append(api_line);
            api_line.after(api_collapse);
        } else {
            $('#show_expired_list').show();
            api_list_expired.append(api_line);
            api_line.after(api_collapse);
            api_line.find('.api-icon-action').remove();
            if (line.is_expired) {
                api_line.find('.api_expiration').html(`<i class="gallery-icon-skull api-skull"></i> <span data-tooltip="${line.expired_on_format}">${line.expired_on_since}</span>`);
            } else {
                api_line.find('.api_expiration').html(`<i class="gallery-icon-skull api-skull"></i> <span>${/\d/.test(line.revoked_on_since) ? line.revoked_on_since : no_time_elapsed}</span> <i data-tooltip="${line.revoked_on_message}" class="icon-info-circled-1 api-info"></i>`);
            }
        }
    });

    apiLineEvent();
    if (reset) resetSection('apikey-display');
}

function apiLineEvent(): void {
    $('.icon-collapse').off('click').on('click', function () {
        const api_collapse = $(`#api_collapse_${$(this).data('api')}`);
        const api_line = $(`#api_${$(this).data('api')}`);

        if (api_collapse.is(':visible')) {
            api_collapse.removeClass('open');
            api_line.removeClass('open').find('.icon-collapse').addClass('close');
            api_collapse.css('display', 'none').find('.api-copy').addClass('api-hide');
        } else {
            api_collapse.addClass('open');
            api_line.addClass('open').find('.icon-collapse').removeClass('close');
            api_collapse.css('display', 'grid');
        }
        resetSection('apikey-display', false, true);
    });

    $('.api-tab-collapse .icon-clone').off('click').on('click', function () {
        copyToClipboard(String($(this).data('copy')), str_copy_key_id, `#${String($(this).data('success'))}`);
    });

    $('.api-tab-line .edit-mode').off('click').on('click', function () {
        openApiEditModal(`#${String($(this).parent().data('api'))}`);
    });

    $('.api-tab-line .delete-mode').off('click').on('click', function () {
        openApiRevokeModal(`#${String($(this).parent().data('api'))}`);
    });
}

function resetSection(selector: string, scroll = true, maxContent = false): void {
    const el = $(`#${selector}`);
    const element = el.get(0) as HTMLElement;
    element.style.maxHeight = maxContent ? 'max-content' : element.scrollHeight + 'px';

    if (selector !== 'account-display' && scroll) {
        setTimeout(() => {
            const sec = $(`#${selector.split('-')[0]}-section`).get(0) as HTMLElement;
            sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 200);
    }
}

function openApiModal(): void {
    $('#api_modal').fadeIn();
    $('#api_key_name').trigger('focus');
    saveApiKeyEvent();
}

function closeApiModal(): void {
    $('#api_modal').fadeOut(() => {
        $('#api_key_name').val('');
        $('select[name="api_expiration"]').val(selected_date).trigger('change');
        $('#api_expiration_date').val('');
        $('#api_secret_key').val('');
        $('#retrieves_keyapi').hide();
        $('#generate_keyapi').show();
        $('#done_apikey').attr('disabled', 'true');
        $('#api_key_copy_success, #api_id_copy_success').addClass('api-hide');
    });
    unbindApiKeyEvents();
}

function successApiModal(secret: string, id: string): void {
    $('#api_secret_key').val(secret);
    $('#api_id_key').val(id);
    $('#generate_keyapi').hide();
    $('#retrieves_keyapi').fadeIn();

    $('#api_secret_copy').off('click').on('click', function () {
        copyToClipboard(secret, str_copy_key_secret, '#api_key_copy_success');
        $('#done_apikey').removeAttr('disabled').on('click', closeApiModal);
    });
    $('#api_id_copy').off('click').on('click', function () {
        copyToClipboard(id, str_copy_key_id, '#api_id_copy_success');
    });
}

function openApiEditModal(selector: string): void {
    const value = $(selector).find('.api_name').text();
    const pkid = $(selector).find('.api-icon-action').data('pkid');
    $('#api_key_edit').val(value);
    $('#api_modal_edit').fadeIn();
    $('#api_key_edit').trigger('focus');
    saveApiEditEvents(pkid);
}

function closeApiEditModal(): void {
    $('#api_modal_edit').fadeOut(() => { $('#api_key_edit').val(''); unbindApiEditEvents(); });
}

function saveApiEditEvents(pkid: unknown): void {
    $('#save_api_edit').on('click', function () {
        const value = String($('#api_key_edit').val() ?? '');
        if (!value) { $('#error_api_key_edit').show(); return; }
        setInfos({ pkid, key_name: value }, 'pwg.users.api_key.edit', () => {
            pwgToaster({ text: str_api_edited, icon: 'success' });
            getAllApiKeys(true);
            closeApiEditModal();
        });
    });
}

function unbindApiEditEvents(): void { $('#save_api_edit').off('click'); }

function openApiRevokeModal(selector: string): void {
    const apiName = $(selector).find('.api_name').text();
    const pkid = $(selector).find('.api-icon-action').data('pkid');
    $('#api_modal_revoke_title').text(sprintf(str_revoke_key, apiName));
    $('#api_modal_revoke').fadeIn();
    saveApiRevokeEvents(pkid);
}

function closeApiRevokeModal(): void {
    $('#api_modal_revoke').fadeOut(() => { $('#api_modal_revoke_title').text(''); unbindApiRevokeEvents(); });
}

function saveApiRevokeEvents(pkid: unknown): void {
    $('#revoke_api_key').on('click', function () {
        setInfos({ pkid }, 'pwg.users.api_key.revoke', () => {
            pwgToaster({ text: str_api_revoked, icon: 'success' });
            getAllApiKeys(true);
            closeApiRevokeModal();
        });
    });
}

function unbindApiRevokeEvents(): void { $('#revoke_api_key').off('click'); }

function copyToClipboard(copy: string, message: string, selector: string | null = null): boolean {
    if (window.isSecureContext && navigator.clipboard) {
        navigator.clipboard.writeText(copy);
        if (selector) $(selector).removeClass('api-hide');
        else pwgToaster({ text: message, icon: 'success' });
        return true;
    } else {
        pwgToaster({ text: str_cant_copy, icon: 'error' });
        return false;
    }
}

function saveApiKeyEvent(): void {
    const handler = () => {
        const api_name = String($('#api_key_name').val() ?? '');
        let api_duration: string | number = String($('select[name="api_expiration"]').val() ?? '');

        if (!api_name) { $('#error_api_key_name').show(); return; }
        if (api_duration === 'custom' && !$('#api_expiration_date').val()) { $('#error_api_key_date').show(); return; }

        unbindApiKeyEvents();

        if (api_duration === 'custom') {
            const today = new Date();
            const custom_date = new Date(String($('#api_expiration_date').val()));
            api_duration = Math.ceil((custom_date.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
        } else {
            api_duration = Number(api_duration) || 1;
        }

        setInfos(
            { key_name: api_name, duration: api_duration },
            'pwg.users.api_key.create',
            (res: unknown) => {
                const r = res as any;
                pwgToaster({ text: str_api_added, icon: 'success' });
                getAllApiKeys(true);
                successApiModal(r.apikey_secret, r.auth_key);
            },
            () => { saveApiKeyEvent(); }
        );
    };

    $('#save_apikey').on('click.apikey', handler);
    $(window).on('keydown.apikey', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); handler(); }
    });
}

function unbindApiKeyEvents(): void {
    $('#api_modal').find('*').addBack().off('.apikey');
    $(window).off('.apikey');
}
