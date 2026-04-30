{literal}
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var dblayer = document.getElementById('dblayer');
    var dbFields = ['dbhost', 'dbuser', 'dbpasswd'];

    function toggleDbFields() {
        var val = dblayer ? dblayer.value : '';
        var hide = (val === 'sqlite' || val === 'pdo-sqlite');
        dbFields.forEach(function(name) {
            var input = document.querySelector('input[name=' + name + ']');
            if (input && input.parentElement && input.parentElement.parentElement) {
                input.parentElement.parentElement.style.display = hide ? 'none' : '';
            }
        });
    }

    toggleDbFields();

    if (dblayer) {
        dblayer.addEventListener('change', function() {
            toggleDbFields();
        });
    }
});
</script>
{/literal}
