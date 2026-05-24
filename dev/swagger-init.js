const el = document.getElementById('swagger-ui');
SwaggerUIBundle({
  url: el.dataset.specUrl,
  dom_id: '#swagger-ui',
  presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
  layout: 'BaseLayout',
  deepLinking: true,
});
