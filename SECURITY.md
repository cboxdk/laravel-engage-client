# Security Policy

## Supported versions

The latest minor of the current major receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email [sn@cbox.dk](mailto:sn@cbox.dk) with a
description and, if possible, a proof of concept. Reports are handled on a best-effort
basis; there is no fixed response time.

Areas of particular interest for this package:

- The ingest key: it must never appear in a log line, an exception message or a
  product event. The package logs reasons and ids, never the key or an address.
- The mail driver's fallback path: it sends around Engage's consent gate. See
  [docs/security/threat-model.md](docs/security/threat-model.md) for why the driver is
  for transactional mail only.
- Steering headers (`X-Engage-*`): they are read and stripped before a message goes
  anywhere, so a provider never sees an app's internal ids.
