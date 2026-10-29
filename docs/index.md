# omnibus/mondial-relay

Mondial Relay for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): relay points,
shipments and their labels, tracking and label reprints, through Mondial Relay's SOAP web services
(`Web_Services.asmx`). The SOAP envelopes are written and read as XML over plain HTTP: `ext-soap`
is not needed. The gateway calls:

| Operation | Web service method |
|---|---|
| pickup points | `WSI4_PointRelais_Recherche` |
| ship | `WSI2_CreationEtiquette` |
| track | `WSI2_TracingColisDetaille` |
| slip (reprint) | `WSI3_GetEtiquettes` |

every one a `POST` to `https://api.mondialrelay.com/Web_Services.asmx` (the `endpoint` option) with
the header `SOAPAction: "http://www.mondialrelay.fr/webservice/<method>"`. Labels are PDF documents
linked from `https://www.mondialrelay.com`. There is no separate test endpoint: the test brand
works on the live one.

## Installation

```sh
composer require omnibus/mondial-relay
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8). `intl` is
used when present to write addresses without accents (`iconv` otherwise). In a Symfony application
with `Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- The **brand code** (`enseigne`, 8 characters) of the shop's Mondial Relay contract, and its
  **private key**: both come with the contract.

Mondial Relay publishes a test brand for everyone, `BDTEST13` with the private key `PrivateK`
(`Api::TEST_ENSEIGNE`, `Api::TEST_PRIVATE_KEY`): it works on the live endpoint and books nothing.
`sandbox: true` fills in these two when `enseigne` and `private_key` are not given.

Every call is signed: `Security` is the MD5 of the brand code and every parameter's value one
after the other, in the documented order, followed by the private key, in capitals.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `enseigne` | string | `BDTEST13` with `sandbox` | yes | the brand code |
| `private_key` | string | `PrivateK` with `sandbox` | yes | the brand's private key |
| `sandbox` | bool | `false` | no | `true`: the public test brand, unless `enseigne` and `private_key` are given; the endpoint does not change |
| `endpoint` | string | `https://api.mondialrelay.com/Web_Services.asmx` | no | the web services' address |
| `collection` | string | `CCC` | no | how the parcels leave the shop (`ModeCol`): `CCC` collected at the shop, `REL` dropped at a relay point |
| `download_labels` | bool | `false` | no | `true`: the label's PDF is downloaded into `Label::$content` (one more `GET`); otherwise only its `url` is given |
| `rates` | list | - | no | the only way this gateway rates ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): Mondial Relay has no rating service |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `insurance` | `''` | the insurance level (`Assurance`), as Mondial Relay codes it; empty: none |
| `instructions` | `''` | delivery instructions (`Instructions`), cut to 31 characters |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | only with `rates` (configured prices) |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | yes (relay points) |
| `GetSlip` | yes, PDF only (`Label::PDF`); another format is not supported |
| `Cancel` | no: a label not handed over expires |
| `Paperless` | no |

## Behaviour

**Text fields.** Mondial Relay takes addresses in capitals and ASCII: every text is transliterated
(`Émile Zola` becomes `EMILE ZOLA`), anything but letters, digits, spaces and `'.,/-` becomes a
space, spaces are collapsed and the field is cut (32 characters, the city 26 when booking, 25 when
searching). A phone keeps its digits and a leading `+`.

**Ship.** One `WSI2_CreationEtiquette` call for the whole shipment:

- the delivery mode (`ModeLiv`) is `Shipment::$service` when set, else `24R` (relay point) when
  the shipment has a `pickupPoint`, else `HOM` (home delivery);
- the relay is `pickupPoint` as `<country>-<number>` (`FR-066974`, the id the pickup point search
  returns), or a bare number taken in the recipient's country;
- the weight is the sum of the parcels' weights in grams, at least 15; the length the first
  parcel's; `NbColis` the number of parcels;
- the shop's reference is the shipment's `reference` (15 characters, `NDossier`);
- sender and recipient each send their name, company, two street lines, city, postcode, country,
  phone and email, with the language `FR`; no shipping date is sent.

The tracking number is the `ExpeditionNum` returned; `url` is `https://www.mondialrelay.com` and
the `URL_Etiquette` returned; `trackingUrl` is
`https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition=<number>&codeMarque=<the brand's first two characters>`.
The format is `application/pdf`.

**Track.** `WSI2_TracingColisDetaille` with the language taken from the locale's first two letters,
in capitals (`fr` gives `FR`). The answer's `STAT` is the parcel's status:

| `STAT` | `TrackingStatus` |
|---|---|
| `80` | `PENDING` (registered) |
| `81` | `IN_TRANSIT`, or `AVAILABLE_FOR_PICKUP` when the latest event is |
| `82` | `DELIVERED` |
| `83` | `EXCEPTION` |

Any other `STAT` is an error (below). Each step with a text and a date becomes an event: its date
`dd/mm/yy` or `dd/mm/yyyy` and its time `HH:MM` are read in `Europe/Paris`, its place
(`Emplacement`) is the `location`, and no `code` is kept. Steps without a text or a readable date
are dropped. Events are sorted oldest first. The step's status is read from its text:

| Text contains | Event status |
|---|---|
| `LIVR` but not `EN COURS` | `DELIVERED` |
| `DISPONIBLE` | `AVAILABLE_FOR_PICKUP` |
| `RETOUR` | `RETURNED` |
| `ANOMALIE` or `INCIDENT` | `EXCEPTION` |
| anything else | `PENDING` when the parcel's status is `PENDING`, else `IN_TRANSIT` |

**Pickup points.** `WSI4_PointRelais_Recherche` around the address's country, city and postcode
(its street and coordinates are not sent), for the `24R` delivery mode, with no search radius (the
service's default), at most `min(30, limit)` points (at least 1). When a parcel is given, its
weight (at least 15 g) is sent so that only points taking it come back; its size is not sent. The
points are kept in the service's order and cut to `limit`.

Each point: id `<country>-<number>`, name the first address line (`LgAdr1`), the second line as the
address's company, the third and fourth as its street, latitude and longitude read with a decimal
comma, `distance` the service's `Distance` as an integer (metres, as the service documents it).
Opening hours come from `Horaires_Lundi` to `Horaires_Dimanche` (ISO weekdays 1 to 7): four
`HHMM` times each, morning open/close and afternoon open/close, `0000` where the point is shut,
written `HH:MM`; a closed day has no entry.

**Slip.** `WSI3_GetEtiquettes` for the tracking number, language `FR`: the 10 x 15 cm PDF (for
thermal printers) when the service gives it, else the A4 one. Its `url`, and its content with
`download_labels`.

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
    'sandbox' => true,          // the public test brand BDTEST13; give enseigne and private_key for the contract's
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
file_put_contents($label->trackingNumber.'.pdf', $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

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
                collection: CCC
                download_labels: false
                rates:
                    - { service: 24R, label: 'Point Relais', to_pickup_point: true, days: 4, countries: [FR, BE],
                        bands: { 500: 449, 1000: 549, 5000: 799 } }
```

The core's Docker harness calls the same gateway from its console:
`docker compose run --rm omnibus track mondial_relay <number>`.

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `mondial_relay`:

- a `STAT` other than `0` (other than `80` to `83` when tracking): the message is Mondial Relay's
  meaning of the code when the package knows it, else `STAT <code>`, and the code is kept in
  `carrierCode`. The codes with a sentence:

  | `STAT` | Message |
  |---|---|
  | `1` | Enseigne invalide |
  | `2` | Numéro d'enseigne vide ou inexistant |
  | `8` | Mot de passe ou hachage invalide |
  | `9` | Ville non reconnue ou non unique |
  | `10` | Type de collecte invalide |
  | `12` | Type de livraison invalide |
  | `20` | Poids du colis invalide |
  | `24` | Numéro d'expédition ou de suivi invalide |
  | `30` | Adresse (L1) invalide |
  | `35` | Code postal invalide |
  | `40` | Pays invalide |
  | `44` | Point relais de livraison invalide |
  | `94` | Colis inexistant |
  | `95` | Compte enseigne non activé |
  | `97` | Clé de sécurité invalide |
  | `99` | Erreur générique du service |

- an HTTP error status, a network error: the HTTP client's message;
- an answer that is not a SOAP envelope (`Unexpected answer to <method>: not a SOAP envelope.`)
  or has no result (`Unexpected answer to <method>.`);
- with `download_labels`, a failed download of the PDF.

A missing `enseigne` or `private_key` (without `sandbox`) is an `InvalidConfigException` when the
gateway is built (`The "mondial_relay" gateway needs: enseigne, private_key.`); `Cancel`,
`Paperless`, a `GetSlip` for another format than PDF, and `Rating` without `rates`, raise
`RequestNotSupportedException` before any call.

No failure carries the private key and the security hash: a network error's message has them masked
as `***` (the key parameters of a URL too), its previous is a copy of the HTTP client's exception
(the same class and code), and no trace of the chain keeps its arguments, where the request's
options stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

Tested offline: `Tests/MondialRelayGatewayTest.php` (the relay search's parameters in their
order and its signature, ids `FR-066974`, coordinates with a decimal comma, distance, opening hours
in two slots and the closed Sunday left out; booking to a relay with `24R`, the addresses in
capitals without accents, the phones; tracking steps sorted oldest first and folded onto the
common scale, dates in `Europe/Paris`; a `STAT` error with its code and sentence; no
cancellation; the credentials required) and `Tests/MondialRelayContractTest.php`, the family's
conformance suite on recorded answers. The recorded answers follow Mondial Relay's integration
documentation. The example above was run in the core's harness on those recorded answers.

Not verified: the README states no live run, on the test brand or on a contract. The distance's
unit is taken as metres from the service's documentation, unchecked against a real answer.

## Responsibility

An independent, unofficial client, not affiliated with Mondial Relay SAS (InPost group). Whoever
runs it complies with Mondial Relay's terms ([mondialrelay.fr conditions
d'utilisation](https://www.mondialrelay.fr/conditions-dutilisation-de-lapplication)): see the
README's Responsibility section.
