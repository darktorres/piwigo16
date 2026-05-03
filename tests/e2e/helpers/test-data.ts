/**
 * Single source of truth for credentials used across e2e specs.
 *
 * The username/password here MUST match what the SQL fixture
 * `dev/fixtures/piwigo-16.x.sql` seeds (it's the same fixture the
 * integration suite loads). global-setup.ts reloads the fixture before
 * every full e2e run, so the install state is rebuilt to a known shape
 * and both suites stay in agreement.
 *
 * If you change the fixture's admin user, change this too.
 */
export const TEST_DATA = {
    admin: { username: 'fixture_admin', password: 'fixture_admin' },
};
