// Allow CSS side-effect imports (import 'foo.css') in TypeScript files.
// Vite handles these at build time; TypeScript just needs to know they exist.
declare module '*.css' {}
