# omnibus/mondial-relay

Mondial Relay for [omniscient/omnibus](https://github.com/glitchr-studio/omnibus):
relay points, labels and tracking through its Web_Services.asmx (plain HTTP, no ext-soap needed).

```php
$gateway = (new MondialRelayGatewayFactory($http))->create($options);   // $http: the HTTP client to call with (the application's, HttpClient::create() in plain PHP); the options below
```

No framework needed: the package requires `omniscient/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        relais:
            factory: mondial_relay
            options:
                enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%'      # the contract's brand code
                private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%'
                sandbox: false         # true: Mondial Relay's public test brand, no contract needed
                collection: CCC        # CCC: collected at the shop; REL: dropped at a relay
                download_labels: false # true: the PDF inside the Label, not only its URL
                rates: [...]           # no rating service: Omnibus\Action\ConfiguredRatingAction
```

No rating nor cancellation service: prices are the shop's contract, and a label not handed over expires.

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
