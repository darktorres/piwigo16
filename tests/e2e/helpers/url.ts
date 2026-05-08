// Build a Piwigo URL from a path. Supports subpath-mounted installs
// (e.g. BASE_URL=http://localhost/piwigo16) where a leading "/" in the
// path would otherwise strip the subpath via standard URL resolution.
export function pwgUrl(path: string): string {
    // Default: local Apache at /piwigo16/. CI/Docker overrides via BASE_URL.
    const baseUrl = (process.env['BASE_URL'] ?? 'http://localhost/piwigo16').replace(/\/+$/, '');
    const cleanPath = path.startsWith('/') ? path : `/${path}`;
    return `${baseUrl}${cleanPath}`;
}

/** Full URL to the web-service endpoint, with optional extra query params. */
export function wsUrl(params: Record<string, string> = {}): string {
    const base = pwgUrl('/index.php?/ws');
    const qs = new URLSearchParams(params).toString();
    return qs ? `${base}&${qs}` : base;
}

/** Full URL to an admin page, with optional ?page= param. */
export function adminUrl(page = ''): string {
    const base = pwgUrl('/index.php?/admin');
    return page ? `${base}&page=${page}` : base;
}

/** Full URL to the identification (login) page. */
export function identificationUrl(): string {
    return pwgUrl('/index.php?/identification');
}
