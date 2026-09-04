// Real per-page bundle entry for plugins_installed.php (docs/PLAN.md
// P48) -- Footer-mode script group. plugins/installed.ts's own real `import` of
// plugins/installed_config.ts guarantees the config file's module
// evaluates first regardless of the order listed here.
import "../plugins/installed_config";
import "../plugins/installed";
