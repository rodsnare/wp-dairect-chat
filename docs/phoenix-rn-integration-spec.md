# Phoenix Cloud Observability – React Native Integration Spec

## Context

The dAIrect Chat WordPress plugin uses Phoenix Cloud for LLM observability (prompt/response tracing via OpenTelemetry). We are diagnosing a connectivity issue from a Docker-based local dev environment (DevKinsta) and need a second data point from an internet-facing deployment to confirm the Phoenix API key and endpoint are correct.

**If this React Native test bench can successfully send traces to Phoenix Cloud when deployed to Firebase, it will:**
1. Confirm the API key is valid
2. Confirm the correct OTLP endpoint URL and auth header format
3. Isolate Docker networking as the blocker for the WordPress plugin

---

## Task

Instrument the SpotHelp React Native test bench with a minimal OpenTelemetry trace sent to Phoenix Cloud on app launch. Deploy to Firebase and verify traces appear in the Phoenix Cloud UI.

---

## Phoenix Cloud Details

| Setting | Value |
|---|---|
| Phoenix UI | `https://app.phoenix.arize.com/s/rodsnare-phoenix` |
| Target project | `SpotHelp_Project` |
| API Key | *(retrieve from Rod — stored in DevKinsta wp-config.php as `DAIRECT_PHOENIX_API_KEY`)* |

**Do not commit the API key to source control.**

---

## Packages

```bash
npm install \
  @opentelemetry/sdk-trace-base \
  @opentelemetry/sdk-trace-web \
  @opentelemetry/exporter-trace-otlp-http \
  @opentelemetry/resources \
  @opentelemetry/semantic-conventions
```

> If React Native polyfills are needed for `fetch`-based OTLP exporter, add `react-native-url-polyfill` and import it at the entry point.

---

## Implementation

### 1. Create `src/telemetry/phoenix.ts`

```typescript
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { WebTracerProvider } from '@opentelemetry/sdk-trace-web';
import { SimpleSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { Resource } from '@opentelemetry/resources';

// TODO: inject via environment config — do not hardcode in production
const PHOENIX_API_KEY  = process.env.PHOENIX_API_KEY ?? '';
const PHOENIX_ENDPOINT = 'https://app.phoenix.arize.com/v1/traces';

export function initPhoenix() {
  if (!PHOENIX_API_KEY) {
    console.warn('[Phoenix] PHOENIX_API_KEY not set — tracing disabled.');
    return null;
  }

  const exporter = new OTLPTraceExporter({
    url: PHOENIX_ENDPOINT,
    headers: { 'api_key': PHOENIX_API_KEY },
  });

  const provider = new WebTracerProvider({
    resource: new Resource({
      'service.name':                   'spothelp-testbench',
      'openinference.project.name':     'SpotHelp_Project',
    }),
  });

  provider.addSpanProcessor(new SimpleSpanProcessor(exporter));
  provider.register();

  return provider.getTracer('spothelp-testbench');
}
```

### 2. Fire a test span at app launch

In your app entry point (e.g. `App.tsx` or `index.ts`):

```typescript
import { initPhoenix } from './src/telemetry/phoenix';

const tracer = initPhoenix();

if (tracer) {
  const span = tracer.startSpan('app-launch-test');
  span.setAttribute('openinference.span.kind', 'CHAIN');
  span.setAttribute('test', true);
  span.end();
}
```

---

## Endpoint notes

We are testing two candidate URLs. Try `v1` first; if that returns an error try `v1beta`:

| Priority | URL | Notes |
|---|---|---|
| 1 | `https://app.phoenix.arize.com/v1/traces` | Standard OTLP/HTTP path |
| 2 | `https://app.phoenix.arize.com/s/rodsnare-phoenix/v1/traces` | Workspace-scoped variant |

Auth header being tested: `api_key: {key}` (Phoenix SDK convention).

---

## Success criteria

- [ ] App launches without crash
- [ ] Network inspector (Flipper / Metro) shows `POST .../v1/traces` returning **HTTP 200**
- [ ] Trace appears in Phoenix Cloud UI under project `SpotHelp_Project`

---

## What to report back

Once the test runs (pass or fail), capture from the network inspector and share with Rod:

1. **Exact URL** that was posted to
2. **HTTP response code** received
3. **Response body** (first 200 chars) if not 200
4. **Auth header name** used (confirm it was `api_key`)

This will be used to update the WordPress plugin's `phoenix-observer.php` to match the working configuration.

---

## For the Claude Code agent implementing this

This spec was written by a Claude Code session working on the `dAIrect-Chat` WordPress plugin. Here is everything the implementing agent needs to know without reading that session's history.

### What we already know doesn't work

The WordPress plugin sends OTLP traces from a PHP server running inside Docker Desktop (DevKinsta on Windows 11). Every attempt returns **HTTP 400** with a Google CDN HTML error page — "Your client has issued a malformed or illegal request." This happens regardless of:
- URL path (`/v1/traces` vs `/s/rodsnare-phoenix/v1/traces`)
- Auth header format (`api_key:` vs `Authorization: Bearer`)

The Google CDN 400 happens before the Phoenix application processes the request, which is why application-level error messages never appear. Our working hypothesis is that Docker's outbound HTTPS is routing through something that mangles the request.

### The API key format

The Phoenix API key is a **JWT token** — it looks like `eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJqdGkiOiJBcGlLZXk6MSJ9...`. This is just Phoenix's API key format; it is not an OAuth token. It should be sent as `api_key: {key}` (not `Authorization: Bearer {key}`).

### The PHP implementation being matched

The WordPress plugin sends OTLP/HTTP JSON. The exact payload structure it uses is below — the React Native SDK will produce something equivalent automatically, but this is here so you can cross-reference if needed:

```json
{
  "resourceSpans": [{
    "resource": {
      "attributes": [
        { "key": "service.name",               "value": { "stringValue": "dairect-chat" } },
        { "key": "openinference.project.name", "value": { "stringValue": "SpotHelp_Project" } }
      ]
    },
    "scopeSpans": [{
      "scope": { "name": "dairect-chat", "version": "1.1.4" },
      "spans": [{
        "traceId":           "<32 hex chars>",
        "spanId":            "<16 hex chars>",
        "name":              "chat",
        "kind":              3,
        "startTimeUnixNano": "<unix ns as string>",
        "endTimeUnixNano":   "<unix ns as string>",
        "attributes": [
          { "key": "openinference.span.kind", "value": { "stringValue": "LLM" } },
          { "key": "input.value",             "value": { "stringValue": "user prompt text" } },
          { "key": "output.value",            "value": { "stringValue": "model response text" } },
          { "key": "llm.model_name",          "value": { "stringValue": "gemini-2.5-flash" } },
          { "key": "session.id",              "value": { "stringValue": "sess_..." } },
          { "key": "llm.token_count.total",   "value": { "intValue": 123 } }
        ],
        "status": { "code": 1 }
      }]
    }]
  }]
}
```

`Content-Type: application/json` with `POST` to the OTLP endpoint.

### Decision tree for the implementing agent

1. **If the React Native app sends traces successfully from Firebase** → Phoenix is working. Capture the exact URL and header from the network inspector and report back. The WordPress plugin will be updated to match.

2. **If the app gets HTTP 400** → the endpoint URL is wrong. Try the workspace-scoped variant: `https://app.phoenix.arize.com/s/rodsnare-phoenix/v1/traces`.

3. **If the app gets HTTP 401 or 403** → the auth header name or value is wrong. Try `Authorization: Bearer {key}` as an alternative to `api_key: {key}`.

4. **If the app gets HTTP 404** → the path is wrong. Check the Phoenix Cloud workspace settings page for the documented OTLP endpoint URL.

5. **If any attempt succeeds**, note the exact working URL + header name and report back to Rod. That's the only output needed from this task.

---

## Contact

Rod Snare — rodsnare@gmail.com  
WordPress plugin repo: `dAIrect-Chat` (private)
