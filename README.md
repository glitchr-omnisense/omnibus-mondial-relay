# omnibus/mondial-relay

Mondial Relay for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): relay points
and tracking through API1, its SOAP web services (`Web_Services.asmx`, plain HTTP, no ext-soap
needed), and labels through API2, the "dual carrier" shipment API (REST, XML).

```php
$gateway = (new MondialRelayGatewayFactory($http))->create($options);   // $http: the HTTP client to call with (the application's, HttpClient::create() in plain PHP); the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        relais:
            factory: mondial_relay
            options:
                # API1: relay points, tracking
                enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%'        # the contract's brand code
                private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%'
                # API2: shipments and their labels
                login: '%env(MONDIAL_RELAY_LOGIN)%'
                password: '%env(MONDIAL_RELAY_PASSWORD)%'
                customer_id: '%env(MONDIAL_RELAY_CUSTOMER_ID)%'
                sandbox: false         # true: API1's public test brand, API2's test environment
                collection: CCC        # CCC: collected at the shop; REL: dropped at a relay
                label_format: 10x15    # A4, A5, 10x15 (PDF) or ZPL
                download_labels: false # true: the PDF inside the Label, not only its URL
                rates: [...]           # no rating service: Omnibus\Action\ConfiguredRatingAction
```

Both credential sets come from Connect (connect.mondialrelay.com, Administration, Configuration des
API): API1's brand code and private key, API2's login, password and customer id (API V2.0).

## Upgrading: labels moved to API2

Mondial Relay stopped the SOAP shipment methods (`WSI2_CreationEtiquette`, which this package used,
`WSI2_CreationExpedition`, `WSI3_GetEtiquettes`): "no longer maintained and should not be used",
blocked for the accounts created since 2024-12-23. A gateway configured with `enseigne` and
`private_key` alone still searches relay points and tracks parcels; `ship()` is an
`InvalidConfigException` naming `login`, `password` and `customer_id`, before any call. Add the three
API2 options. For an account opened before 2024-12-23, `legacy_labels: true` keeps the SOAP labels
(and `slip()`) during the transition, deprecated (`E_USER_DEPRECATED` on each label). With API2,
`slip()` is not supported: API2 has no reprint, a label's `url` is where it is printed again.

## A label is a purchase

API2 takes no idempotency key and its `OrderNo` (the shipment's `reference`) is free data: nothing
says a second request with the same reference is refused. The package sends a shipment request once
(no retry, a 429 held by the carrier's wait). A request lost after it left - a timeout, a 5xx - is a
`CarrierException` with `carrierCode` `uncertain`, whose message says the shipment may exist: look
for its reference in Connect before booking again. A booked label whose PDF download fails
(`download_labels`) is returned with its `url` and no content, not as a failure.

## Verified, and not

Read on 2026-11-11 from Mondial Relay's published documents: Presentation of WebServices V-1.2
(February 2026), Web Service V-5.14 (API1, January 2025), Web Service Dual Carrier V-2.7.1 (API2,
June 2024), the WSDL of `Web_Services.asmx`, the Connect user manual V-1.3 (February 2026).

- Verified against the documents: API1's methods, parameters and signature, its test brand
  `BDTEST13` / `TestAPI1key` (the key changed in V-5.12), its STAT codes; API2's endpoints
  (`connect-api.mondialrelay.com`, `connect-api-sandbox.mondialrelay.com`), headers, Context,
  output types and formats, delivery and collection modes, shipment and address fields, error codes.
- Not verified: no live call was made (no account): every test runs on answers written from the
  documents. API2's XSDs (`mondialrelay.fr/media/51911`, `51914`) answered 403: the namespaces are
  the specification's example's (`http://www.example.org/Request`), and a `Status`'s code, level and
  message are read as attributes or child elements, whichever comes. The relay's `Location` is sent
  as `FR-066974` (the specification's examples write `FR-66974`; its regex allows no hyphen).
  Whether `customer_id` equals the brand code is not said. API2's sandbox credentials are not
  published (Mondial Relay issues them). The tracking page URL (`codeMarque`) is not documented.
  Web Service V-5.14's example hash is the one of the former key `PrivateK`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Mondial Relay SAS (InPost group). It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Mondial Relay's terms
([mondialrelay.fr conditions
d'utilisation](https://www.mondialrelay.fr/conditions-dutilisation-de-lapplication)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
