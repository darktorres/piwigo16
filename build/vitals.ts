// Real User Monitoring (docs/PLAN.md P1, item 11b): reports real
// Core Web Vitals from actual visitors to /analytics/vitals, which logs
// them as structured JSON on the Monolog "app" channel (Piwigo\Controller\
// VitalsController). Lab-data equivalent (Lighthouse CI) already exists;
// this is the field-data half — real connections, real devices.
import { onCLS, onFCP, onINP, onLCP, onTTFB, type Metric } from "web-vitals";

// Resolved against this script's own URL (dist/vitals.js), not a hardcoded
// root-relative path -- Piwigo can be served under any Apache document root
// prefix (see vite.config.ts), so "/analytics/vitals" alone 404s whenever
// the app isn't mounted at the domain root.
const VITALS_ENDPOINT = new URL(
  /* @vite-ignore */ "../analytics/vitals",
  import.meta.url,
).toString();

function report(metric: Metric): void {
  const body = JSON.stringify({
    name: metric.name,
    value: metric.value,
    id: metric.id,
    rating: metric.rating,
    url: location.pathname,
  });

  navigator.sendBeacon(VITALS_ENDPOINT, body);
}

onCLS(report);
onFCP(report);
onINP(report);
onLCP(report);
onTTFB(report);
