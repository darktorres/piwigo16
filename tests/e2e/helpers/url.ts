// Build a Piwigo URL from a path. Supports subpath-mounted installs
// (e.g. BASE_URL=http://localhost/piwigo16) where a leading "/" in the
// path would otherwise strip the subpath via standard URL resolution.
export function pwgUrl(path: string): string {
    // Default: local Apache at /piwigo16/. CI/Docker overrides via BASE_URL.
    const baseUrl = (process.env['BASE_URL'] || 'http://localhost/piwigo16').replace(/\/+$/, '');
    const cleanPath = path.startsWith('/') ? path : `/${path}`;
    return `${baseUrl}${cleanPath}`;
}
