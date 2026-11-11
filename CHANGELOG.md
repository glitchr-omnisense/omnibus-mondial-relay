# Changelog

## [Unreleased]

### Changed
- Mondial Relay's tracking rule declared as a limit: 6 tracking requests a day per parcel
  (`policy.json`: `"scope": "resource"`, `"per": "parcel"`, operation `trackParcel`), where the
  notes said no scope could count it. `TrackingAction` counts each request under `trackParcel`, the
  tracking number named, through the guard the factory gives it (`Config::guard()->perResource()`),
  in the same limiter as the gateway's guard; the 7th request of a parcel in a day is a
  `UsageLimitReachedException`, never sent. `MondialRelayGatewayFactory::usagePolicy()`, `POLICY`.
  Needs omnisense/contracts **2f78f0e** (the resource scope, `PolicyGuard::perResource()`); with an
  older kernel (1568f2c) the factory reads policy.json without that limit and nothing is counted
  per parcel, as before; only that kernel's `PolicyExport` reports the file as unreadable.
  Tests/ParcelTrackingLimitTest.
- Labels go through Mondial Relay's API2, the dual carrier shipment API (`ShipmentApi`: one
  `ShipmentCreationRequest` POSTed to `connect-api.mondialrelay.com/api/shipment`, the sandbox's
  with `sandbox`). The SOAP `WSI2_CreationEtiquette` the package used is stopped ("no longer
  maintained and should not be used", Presentation of WebServices V-1.2, February 2026; Web Service
  V-5.11, April 2024; blocked for the accounts created since 2024-12-23). New options `login`,
  `password`, `customer_id` (API2's credentials, from Connect), `shipment_endpoint`, `culture`,
  `label_format` (A4, A5, 10x15, ZPL); shipment options `content`, `customer_no`, `language`.
  Relay points and tracking stay on API1 (SOAP), still the maintained services.
- **Upgrading**: a gateway configured with `enseigne` and `private_key` alone keeps its relay points
  and tracking; `ship()` is an `InvalidConfigException` naming `login`, `password`, `customer_id`
  (and `legacy_labels`), before any call. `legacy_labels: true` keeps the SOAP labels for an account
  created before 2024-12-23 during the transition (deprecated: `E_USER_DEPRECATED` on each label).
- `slip()` (`WSI3_GetEtiquettes`, stopped likewise) is offered only while labels come from the SOAP
  service: API2 has no reprint, a label's `url` is where it is printed again.
- API1's public test key is `TestAPI1key` (`Api::TEST_PRIVATE_KEY`), as Web Service V-5.12 changed
  it; `sandbox: true` fills it in.
- API1's `STAT` messages are Web Service V-5.14's wording; several of the former sentences named the
  wrong field (35, 40, 44).
- A booked label whose PDF download fails (`download_labels`) is returned with its number and `url`,
  no content, instead of a failure that lost the shipment number.
- A shipment request lost after it left (a network error, a timeout, a 5xx) is a `CarrierException`
  with `carrierCode` `uncertain`, saying the shipment may exist: API2 has no idempotency key.
- `policy.json` read again on 2026-11-11 (API1 V-5.14, API2 V-2.7.1, Presentation V-1.2): the terms'
  source, the label and tracking rules in the notes. The PHPStan baseline shrank from 23 entries to
  11.

### Added
- On omnisense/contracts: the carrier's usage policy as data (`policy.json`, named in
  `composer.json`'s `extra.omnisense.policy`; docs/index.md, Usage policy), and its waits honoured:
  the API client takes the gateway's Backoff, a 429 is a `RateLimitedException`, the next calls are
  held unsent, nothing is retried. Requires omnisense/contracts. `phpstan.neon` at level max,
  `phpstan-baseline.neon` what was there.

### Fixed
- A `CarrierException` no longer carries the private key and the security hash. The message quoted
  the HTTP client's (the whole URL), the previous was the client's own exception, and every trace of
  the chain kept the arguments of the calls under it (the request's options, the `Api` with its
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
