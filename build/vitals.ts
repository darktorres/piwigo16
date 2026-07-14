// Real User Monitoring (docs/PLAN-REPLAY.md P1, item 11b): reports real
// Core Web Vitals from actual visitors to /analytics/vitals, which logs
// them as structured JSON on the Monolog "app" channel (Piwigo\Controller\
// VitalsController). Lab-data equivalent (Lighthouse CI) already exists;
// this is the field-data half — real connections, real devices.
import { onCLS, onFCP, onINP, onLCP, onTTFB, type Metric } from "web-vitals";

function report(metric: Metric): void {
  const body = JSON.stringify({
    name: metric.name,
    value: metric.value,
    id: metric.id,
    rating: metric.rating,
    url: location.pathname,
  });

  navigator.sendBeacon("/analytics/vitals", body);
}

onCLS(report);
onFCP(report);
onINP(report);
onLCP(report);
onTTFB(report);
