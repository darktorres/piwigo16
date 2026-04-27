declare var plugins: Array<{ name: string; state: string }>;
declare var hasActivePlugins: boolean;
declare var nbActivatedPlugins: number;
declare var no_active_plugin: string;
declare var error_occured: string;

$(document).ready(function () {
    jQuery.ajax({
        url: 'ws.php?format=json&method=pwg.plugins.getList',
        type: 'GET',
        dataType: 'JSON',
        success: function (data: { result: Array<{ name: string; state: string }> }) {
            plugins = data.result;
            hasActivePlugins = false;
            nbActivatedPlugins = 0;
            plugins.forEach((plugin) => {
                if (plugin.state === 'active') {
                    hasActivePlugins = true;
                    $('#pluginList ul').append('<li>' + plugin.name + '</li>');
                    $('#pluginList ul i').hide();
                    nbActivatedPlugins++;
                }
            });
            if (!hasActivePlugins) {
                $('#pluginList ul i').hide();
                $('#pluginList ul').append('<p>' + no_active_plugin + '</p>');
            }
            $('.badge-number').append(String(nbActivatedPlugins));
        },
        error: function () {
            $('.badge-number').append('0');
            $('#pluginList ul').append('<p>' + error_occured + '</p>');
            $('#pluginList ul i').hide();
        },
    });
});

export {};
