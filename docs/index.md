# omnibus/mondial-relay

Mondial Relay for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): relay points,
shipments and their labels, tracking. Mondial Relay runs two APIs with their own credentials
(Presentation of WebServices V-1.2, February 2026):

- **API1**, the SOAP web services (`Web_Services.asmx`), for the relay point search and tracking.
  The SOAP envelopes are written and read as XML over plain HTTP: `ext-soap` is not needed.
- **API2**, the "dual carrier" shipment API (REST, XML), for shipments and their labels: one
  method, `POST /api/shipment`.

| Operation | Service | Status at Mondial Relay (read 2026-11-11) |
|---|---|---|
| pickup points | API1 `WSI4_PointRelais_Recherche` | maintained |
| track | API1 `WSI2_TracingColisDetaille` | maintained |
| ship | API2 `ShipmentCreationRequest` | maintained, the only one for new accounts |
| ship, with `legacy_labels` | API1 `WSI2_CreationEtiquette` | stopped (V-5.11, April 2024), blocked for accounts created since 2024-12-23 |
| slip (reprint) | API1 `WSI3_GetEtiquettes` | stopped likewise; API2 has no reprint |

API1: every call a `POST` to `https://api.mondialrelay.com/Web_Services.asmx` (the `endpoint` option)
with the header `SOAPAction: "http://www.mondialrelay.fr/webservice/<method>"`; there is no separate
test endpoint, the test brand works on the live one. API2: a `POST` of a `ShipmentCreationRequest`
to `https://connect-api.mondialrelay.com/api/shipment`, or
`https://connect-api-sandbox.mondialrelay.com/api/shipment` in the test environment (the
`shipment_endpoint` option), with `Accept: application/xml` and `Content-Type: text/xml`.

## Installation

```sh
composer require omnibus/mondial-relay
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8). `intl` is
used when present to write addresses without accents (`iconv` otherwise). In a Symfony application
with `Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

Both sets come from Connect (https://connect.mondialrelay.com): the account's administrator gives a
user the right "Configuration des API" (Administration, Gestion des Utilisateurs), logs out and in
again, and finds the parameters of API1 and API2 under Administration, Configuration des API
(Connect user manual V-1.3; Dual Carrier V-2.7.1, Credentials).

- **API1**: the **brand code** (`enseigne`, 8 characters) and its **private key**. Every call is
  signed: `Security` is the MD5 of the brand code and every parameter's value one after the other,
  in the documented order, followed by the private key, in capitals.
- **API2** ("API Version V2.0"): a **login** (`BDTEST@business-api.mondialrelay.com` in the
  specification's example), a **password** and a **customer id** (8 characters), sent in the
  request's `Context` with the culture (`fr-FR`) and `VersionAPI` `1.0`.

Mondial Relay publishes an API1 test brand for everyone, `BDTEST13` with the private key
`TestAPI1key` (Web Service V-5.14; it was `PrivateK` before V-5.12, September 2024) -
`Api::TEST_ENSEIGNE`, `Api::TEST_PRIVATE_KEY`: it works on the live endpoint and books nothing.
API2's test credentials are not published: Mondial Relay issues them with the sandbox.
`sandbox: true` fills in the API1 test brand when `enseigne` and `private_key` are not given, and
points `shipment_endpoint` at API2's test environment.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `enseigne` | string | `BDTEST13` with `sandbox` | yes | API1's brand code |
| `private_key` | string | `TestAPI1key` with `sandbox` | yes | API1's private key |
| `login` | string | - | for labels | API2's login |
| `password` | string | - | for labels | API2's password |
| `customer_id` | string | - | for labels | API2's customer id; the three together, or none |
| `sandbox` | bool | `false` | no | `true`: API1's public test brand (unless `enseigne` and `private_key` are given) and API2's test environment |
| `endpoint` | string | `https://api.mondialrelay.com/Web_Services.asmx` | no | API1's address |
| `shipment_endpoint` | string | `https://connect-api.mondialrelay.com/api/shipment`, the sandbox's with `sandbox` | no | API2's address |
| `culture` | string | `fr-FR` | no | API2's `Culture`: the language the request is processed in |
| `collection` | string | `CCC` | no | how the parcels leave the shop (`CollectionMode`, `ModeCol`): `CCC` collected at the shop, `REL` dropped at a relay point |
| `label_format` | string | `10x15` | no | `A4`, `A5`, `10x15` (a PDF's URL) or `ZPL` (`Generic_ZPL_10x15_200dpi`, inline) |
| `download_labels` | bool | `false` | no | `true`: the label's PDF is downloaded into `Label::$content` (one more `GET`); otherwise only its `url` is given |
| `legacy_labels` | bool | `false` | no | `true`, without API2's credentials: labels through the stopped `WSI2_CreationEtiquette`, and `slip()`; deprecated, for an account created before 2024-12-23 during the transition |
| `rates` | list | - | no | the only way this gateway rates ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): Mondial Relay has no rating service |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `insurance` | `''` | the insurance level (`ASS` option; `Assurance` with `legacy_labels`), as Mondial Relay codes it; empty: none |
| `instructions` | `''` | delivery instructions (`DeliveryInstruction`), cut to 30 characters (31 with `legacy_labels`) |
| `content` | `''` | what the parcel holds, printed on the label (`Parcel/Content`), 40 characters |
| `customer_no` | `''` | the shop's customer reference (`CustomerNo`), 9 capitals or digits |
| `language` | `''` | the `LNG` option (a two-letter code; the specification does not say more) |
| `label_format` | the gateway's | this shipment's label format |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | only with `rates` (configured prices) |
| `Shipping` | yes: through API2 with `login`, `password`, `customer_id`; through the SOAP service with `legacy_labels`; otherwise an `InvalidConfigException` naming the API2 options |
| `Tracking` | yes |
| `Pickup` | yes (relay points) |
| `GetSlip` | only while labels come from the SOAP service (no API2 credentials, or `legacy_labels`), PDF only |
| `Cancel` | no: a label not handed over expires |
| `Paperless` | no |

## Behaviour

**Text fields.** Mondial Relay takes addresses in capitals and ASCII: every text is transliterated
(`Émile Zola` becomes `EMILE ZOLA`), anything but letters, digits, spaces and `'.,/-` becomes a
space, spaces are collapsed and the field is cut (API2: the name, company and second street line
30, the street 40, the city 30; API1: 32, the city 25 when searching). A phone keeps its digits and
a leading `+`.

**Ship (API2).** One `ShipmentCreationRequest` for the shipment:

- the delivery mode (`DeliveryMode/@Mode`) is `Shipment::$service` when set, else `24R` (relay
  point) when the shipment has a `pickupPoint`, else `HOM` (home delivery); its `Location` the relay
  as `<country>-<number>` (`FR-066974`, the id the pickup point search returns; a bare number is
  taken in the recipient's country);
- the collection mode is the `collection` option; one `Parcel` per parcel, its weight in grams (at
  least 10) and its length, width and depth in cm when known; `ParcelCount` the number of parcels
  (Mondial Relay takes several only in `24L`, it answers 10070 otherwise);
- `OrderNo` is the shipment's `reference` in capitals, digits, `-` and `_`, 15 characters;
- sender and recipient: the name as `AddressAdd1`, the company as `AddressAdd2`, the first street
  line as `Streetname`, the second as `AddressAdd3`, postcode, city, country, the phone as
  `PhoneNo` and `MobileNo`, the email;
- the output: `PdfUrl` with `A4`, `A5` or `10x15`, or `ZplCode` with `Generic_ZPL_10x15_200dpi`.

The tracking number is the `ShipmentNumber` returned. A PDF label's `url` is the `Output` returned
(a Connect link); a ZPL label's content is the `Output` decoded from base64 and its format
`application/zpl`. `trackingUrl` is
`https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition=<number>&codeMarque=<the brand's first two characters>`.
A `Status` of level `Warning` ("Statement ignored") does not stop the booking; any other is an
error. Labels must not be altered or resized (Presentation of WebServices V-1.2).

**Ship (`legacy_labels`).** `WSI2_CreationEtiquette` as before: `ModeLiv` as above, the relay in
`LIV_Rel_Pays` and `LIV_Rel`, the total weight (at least 15 g), `NbColis`, `NDossier` the reference,
the label's `url` `https://www.mondialrelay.com` and the `URL_Etiquette` returned.

**Track.** `WSI2_TracingColisDetaille` with the language taken from the locale's first two letters,
in capitals (`fr` gives `FR`). Mondial Relay asks that a shipment be tracked no more than 4 to 6
times a day and never once delivered or returned (EDI or webhooks for more): the 7th tracking
request of a parcel in a day is a `UsageLimitReachedException`, never sent (Usage policy, below);
stopping after delivery stays the caller's. The answer's `STAT` is the parcel's status:

| `STAT` | `TrackingStatus` |
|---|---|
| `80` | `PENDING` (registered) |
| `81` | `IN_TRANSIT`, or `AVAILABLE_FOR_PICKUP` when the latest event is |
| `82` | `DELIVERED` |
| `83` | `EXCEPTION` |

Any other `STAT` is an error (below). Each step (`Libelle`, `Date`, `Heure`, `Emplacement`) with a
text and a date becomes an event: its date `dd/mm/yy` or `dd/mm/yyyy` and its time `HH:MM` are read
in `Europe/Paris`, its place is the `location`, and no `code` is kept. Steps without a text or a
readable date are dropped. Events are sorted oldest first. The step's status is read from its text:

| Text contains | Event status |
|---|---|
| `LIVR` but not `EN COURS` | `DELIVERED` |
| `DISPONIBLE` | `AVAILABLE_FOR_PICKUP` |
| `RETOUR` | `RETURNED` |
| `ANOMALIE` or `INCIDENT` | `EXCEPTION` |
| anything else | `PENDING` when the parcel's status is `PENDING`, else `IN_TRANSIT` |

**Pickup points.** `WSI4_PointRelais_Recherche` around the address's country, city and postcode
(its street and coordinates are not sent), for the `24R` delivery mode, with no search radius (the
service's default, 50 km), at most `min(30, limit)` points (at least 1). When a parcel is given,
its weight (at least 15 g) is sent so that only points taking it come back; its size is not sent.
The points are kept in the service's order and cut to `limit`. One call per search: Mondial Relay
does not let the whole network be fetched.

Each point: id `<country>-<number>`, name the first address line (`LgAdr1`), the second line as the
address's company, the third and fourth as its street, latitude and longitude read with a decimal
comma, `distance` the service's `Distance` as an integer (metres, as Web Service V-5.14 says).
Opening hours come from `Horaires_Lundi` to `Horaires_Dimanche` (ISO weekdays 1 to 7): four
`HHMM` times each, morning open/close and afternoon open/close, `0000` where the point is shut,
written `HH:MM`; a closed day has no entry.

**Slip.** Only while labels come from the SOAP service: `WSI3_GetEtiquettes` for the tracking
number, language `FR`, the 10 x 15 cm PDF when the service gives it, else the A4 one. An API2
label is printed again from its `url`.

## A label is a purchase

API2 offers no idempotency key, and `OrderNo` is free data (nothing in the specification says a
reference sent twice is refused): a shipment request is sent once. The HTTP client is kept with its
retries off, a 429 is a `RateLimitedException` and the next calls wait for its `Retry-After`,
unsent. A request lost after it left - a network error, a timeout, a 5xx - may come after
Mondial Relay booked the shipment: it is a `CarrierException` with `carrierCode` `uncertain`, its
message saying so; look for the reference in Connect before booking again. A 4xx is not
`uncertain`. With `download_labels`, a PDF that cannot be downloaded leaves the `Label` with its
number and `url`, no content: the booking stands and the PDF is fetched later.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$relay = (new MondialRelayGatewayFactory(HttpClient::create()))->create([
    'sandbox' => true,          // API1's public test brand, API2's test environment
    'login' => getenv('MONDIAL_RELAY_LOGIN'),           // API2's sandbox credentials, from Mondial Relay
    'password' => getenv('MONDIAL_RELAY_PASSWORD'),
    'customer_id' => getenv('MONDIAL_RELAY_CUSTOMER_ID'),
    'download_labels' => true,
    'rates' => [
        ['service' => '24R', 'label' => 'Point Relais', 'to_pickup_point' => true, 'days' => 4,
            'countries' => ['FR', 'BE'], 'bands' => [500 => 449, 1000 => 549, 5000 => 799]],
    ],
]);

$customer = new Address('Émile Zola', ['21 bis rue de Bruxelles'], '75009', 'Paris', 'FR', phone: '06 12 34 56 78');

$points = $relay->pickupPoints($customer, 5, new Parcel(800));
foreach ($points as $point) {
    printf("%s %-30s %5d m\n", $point->id, $point->name, $point->distance);
}

$shipment = new Shipment(
    new Address('Glitch Art', ['12 rue de la Paix'], '75002', 'Paris', 'FR', phone: '+33102030405'),
    $customer,
    [new Parcel(800)],
    pickupPoint: $points[0]->id,
    reference: 'CMD-1042',
);

$label = $relay->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', $label->content ?? '');
echo $label->trackingNumber, ' ', $label->url, ' ', $label->trackingUrl, "\n";

$tracking = $relay->track($label->trackingNumber);
echo $tracking->status->value, "\n";
foreach ($tracking->events as $event) {
    echo $event->at->format('Y-m-d H:i'), ' ', $event->description, "\n";
}
```

In Symfony:

```yaml
omnibus:
    gateways:
        relais:
            factory: mondial_relay
            options:
                enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%'
                private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%'
                login: '%env(MONDIAL_RELAY_LOGIN)%'
                password: '%env(MONDIAL_RELAY_PASSWORD)%'
                customer_id: '%env(MONDIAL_RELAY_CUSTOMER_ID)%'
                collection: CCC
                label_format: 10x15
                rates:
                    - { service: 24R, label: 'Point Relais', to_pickup_point: true, days: 4, countries: [FR, BE],
                        bands: { 500: 449, 1000: 549, 5000: 799 } }
```

The core's Docker harness calls the same gateway from its console:
`docker compose run --rm omnibus track mondial_relay <number>`.

## Usage policy

`policy.json` (named in `composer.json`'s `extra.omnisense.policy`) declares one limit: a shipment
tracked 6 times a day at most (Mondial Relay says 4 to 6), counted per parcel
(`{"scope": "resource", "per": "parcel", "operations": ["trackParcel"]}`). `TrackingAction` counts
it itself, the tracking number named, through the guard the factory gives it
(`Config::guard()->perResource()`, in the registry's limiter), while the gateway's guard counts
`tracking` as before, so a site's limit on every operation counts each request once. A site may
lower it: `'policy' => ['rateLimits' => [['max' => 4, 'window' => 'day', 'scope' => 'resource',
'per' => 'parcel', 'operations' => ['trackParcel']]]]`.
Mondial Relay publishes no request rate. The notes keep what the documents ask (read 2026-11-11): no batch
calls, requests spread over the day as end users need them; never tracked once delivered or
returned; one call per point search, never the
whole network, 30 points at most, a radius up to 75 km (REL) or 100 km (24R, 24L); API2's labels
not altered nor resized, a merchant's own printing validated by Mondial Relay first. See
omnisense/omnibus' [Usage policies and rate
limits](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/policies.md).

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `mondial_relay`:

- API1: a `STAT` other than `0` (other than `80` to `83` when tracking): the message is Web Service
  V-5.14's wording of the code (`Incorrect shipment number` for 24, `Incorrect security key` for
  97...), else `STAT <code>`, and the code is kept in `carrierCode`;
- API2: a `Status` of level `Error` or `Critical Error` in the answer's `StatusList` (10001 invalid
  user or password, 10044 invalid postcode, 10070 multi-parcel forbidden for the product...): its
  message, the first error's code in `carrierCode`, the others' codes counted in the message; an
  answer with no `ShipmentNumber` is `Mondial Relay booked no shipment.`;
- an HTTP error status, a network error: the HTTP client's message (for API2's shipment, a network
  error, a timeout or a 5xx carry `carrierCode` `uncertain`, above);
- an answer that is not a SOAP envelope (`Unexpected answer to <method>: not a SOAP envelope.`), has
  no result (`Unexpected answer to <method>.`), or is not a `ShipmentCreationResponse`;
- with `download_labels` and the SOAP service's `slip()`, a failed download of the PDF.

A missing `enseigne` or `private_key` (without `sandbox`) is an `InvalidConfigException` when the
gateway is built (`The "mondial_relay" gateway needs: enseigne, private_key.`), and so is one or two
of `login`, `password`, `customer_id` without the others. `ship()` without API2's credentials nor
`legacy_labels` is an `InvalidConfigException` naming `login`, `password`, `customer_id` and
`legacy_labels`, before any call; so is a `label_format` other than A4, A5, 10x15 or ZPL. `Cancel`,
`Paperless`, `GetSlip` with API2 or for another format than PDF, and `Rating` without `rates`, raise
`RequestNotSupportedException` before any call.

No failure carries the credentials: a network error's message has the private key, the API2 login
and password and the security hash masked as `***` (the key parameters of a URL too), its previous
is a copy of the HTTP client's exception (the same class and code), and no trace of the chain keeps
its arguments, where the request's options stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/errors.md#no-secret-in-a-failure)).
API2's answer repeats the request's `Context`, password included: no part of an answer is quoted in
a failure.

## Verified, and not

Read on 2026-11-11: [Presentation of WebServices
V-1.2](https://storage.mondialrelay.fr/Presentation%20of%20WebServices%20V1.2-1.pdf) (February
2026), [Web Service V-5.14](https://storage.mondialrelay.fr/web-service-solution-v514-EN.pdf) (API1,
January 2025), [Web Service Dual Carrier
V-2.7.1](https://storage.mondialrelay.fr/web-service-dual-carrier-v-271.pdf) (API2, June 2024), the
WSDL of `Web_Services.asmx`, the [Connect user manual
V-1.3](https://storage.mondialrelay.fr/Manuel%20Utilisateur%20-%20CONNECT%20V1.3-1.pdf) (February
2026).

Tested offline: `Tests/MondialRelayGatewayTest.php` (the relay search's parameters in their order
and its signature, ids `FR-066974`, coordinates with a decimal comma, distance, opening hours; the
documented signature example; booking to a relay through API2 - the endpoint, headers, Context,
modes, parcel, options, addresses in capitals without accents, the phones -, a ZPL label inline,
a failed PDF download keeping the label, API2's errors and warnings, a lost answer `uncertain`, the
old credentials alone naming the new options, `legacy_labels`, part of API2's credentials refused;
tracking steps folded onto the common scale; a `STAT` error with its code and sentence),
`Tests/MondialRelayContractTest.php` and `Tests/MondialRelayLegacyContractTest.php`, the family's
conformance suite with API2 and with `legacy_labels`, and `Tests/UnreachableChainTest.php` (no
credential in a failure, API2 included). The answers are written from the documents above.

Not verified:

- no live call, on the test brand, API2's sandbox or a contract: there is no account;
- API2's XSDs (`https://www.mondialrelay.fr/media/51911/...Request.1.0.xsd`, `51914/...Response`)
  answered 403: the request's namespace is the specification's example's
  (`http://www.example.org/Request`), the answer is read whatever its namespace, and a `Status`'s
  code, level and message are read as attributes or as child elements;
- the relay's `Location` is sent as `FR-066974`; the specification's examples write `FR-66974`
  and its field regex allows no hyphen;
- the `LNG` option's values, whether `customer_id` equals the brand code, how long an API2 PDF link
  lasts;
- the tracking page's address (`codeMarque`) is not documented;
- the distance's unit is the documentation's (metres), unchecked against a real answer;
- Web Service V-5.14 gives `TestAPI1key` as the test key, but its example hash is the one of the
  former key `PrivateK`.

## Responsibility

An independent, unofficial client, not affiliated with Mondial Relay SAS (InPost group). Whoever
runs it complies with Mondial Relay's terms ([mondialrelay.fr conditions
d'utilisation](https://www.mondialrelay.fr/conditions-dutilisation-de-lapplication)): see the
README's Responsibility section.
