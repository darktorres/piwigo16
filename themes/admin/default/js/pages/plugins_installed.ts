// Real per-page bundle entry for plugins_installed.php (docs/PLAN.md
// P48) -- Footer-mode script group. plugins/installed.ts's own real `import` of
// plugins/installedConfig.ts guarantees the config file's module
// evaluates first regardless of the order listed here.
import "../plugins/installedConfig";
import "../plugins/installed";
