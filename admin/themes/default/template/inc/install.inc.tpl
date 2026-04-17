<script>
  document.addEventListener('DOMContentLoaded', function() {
    var dblayer = document.getElementById('dblayer');
    if (!dblayer) return;

    function updateDbFields() {
      var isSqlite = (dblayer.value === 'sqlite' || dblayer.value === 'pdo-sqlite');
      document.querySelectorAll('input[name=dbhost],input[name=dbuser],input[name=dbpasswd]').forEach(function(input) {
        var row = input.parentElement && input.parentElement.parentElement;
        if (row) row.style.display = isSqlite ? 'none' : '';
      });
    }

    updateDbFields();
    dblayer.addEventListener('change', updateDbFields);
  });
</script>
