<?php

namespace Omnibus\MondialRelay;

use Omnibus\Config;
use Omnibus\GatewayFactory;
use Omnibus\MondialRelay\Action\GetSlipAction;
use Omnibus\MondialRelay\Action\PickupAction;
use Omnibus\MondialRelay\Action\ShippingAction;
use Omnibus\MondialRelay\Action\TrackingAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     # API1 (SOAP): relay points and tracking
 *     enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%'       # the brand code of the contract (8 characters)
 *     private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%'
 *     # API2 (REST, dual carrier): shipments and their labels
 *     login: '%env(MONDIAL_RELAY_LOGIN)%'             # Connect, Configuration des API, API V2.0
 *     password: '%env(MONDIAL_RELAY_PASSWORD)%'
 *     customer_id: '%env(MONDIAL_RELAY_CUSTOMER_ID)%'
 *     sandbox: true                                   # API1's public test brand, API2's test environment
 *     collection: CCC                                 # CCC: collected at the shop; REL: dropped at a relay
 *     label_format: 10x15                             # A4, A5, 10x15 (PDF) or ZPL
 *     download_labels: false                          # true: the PDF inline in the Label, not only its URL
 *     legacy_labels: false                            # true, without API2's credentials: the deprecated WSI2_CreationEtiquette
 *     rates: [...]                                    # no rating service: Omnibus\Action\ConfiguredRatingAction
 *
 * No rating nor cancellation service: prices are the shop's contract, and a
 * label not handed over simply expires.
 */
final class MondialRelayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        if ($config->get('sandbox')) {
            $config->defaults(['enseigne' => Api::TEST_ENSEIGNE, 'private_key' => Api::TEST_PRIVATE_KEY, 'shipment_endpoint' => ShipmentApi::SANDBOX_ENDPOINT]);
        }
        $config->defaults([
            'omnibus.factory_name' => 'mondial_relay',
            'omnibus.factory_title' => 'Mondial Relay',
            'omnibus.policy' => __DIR__.'/policy.json',
            'omnibus.required_options' => ['enseigne', 'private_key'],
            'endpoint' => Api::ENDPOINT,
            'shipment_endpoint' => ShipmentApi::ENDPOINT,
            'culture' => 'fr-FR',
            'collection' => 'CCC',
            'label_format' => '10x15',
            'download_labels' => false,
            'legacy_labels' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();
                $shipments = self::api2($c)
                    ? new ShipmentApi($http, (string) $c->string('login'), (string) $c->string('password'), (string) $c->string('customer_id'), (string) $c->string('shipment_endpoint'), (string) $c->string('culture'), $c->backoff())
                    : null;

                return new Api($http, (string) $c->string('enseigne'), (string) $c->string('private_key'), (string) $c->string('endpoint'), $c->backoff(), $shipments);
            },
            'omnibus.action.pickup' => new PickupAction(),
            'omnibus.action.shipping' => static fn (Config $c) => new ShippingAction((string) $c->string('collection'), $c->bool('download_labels'), (string) $c->string('label_format'), $c->bool('legacy_labels')),
            'omnibus.action.tracking' => new TrackingAction(),
            // WSI3_GetEtiquettes: stopped for the accounts opened since 2024-12-23, and API2 has no reprint (a label's
            // url is its reprint). Kept where the labels still come from the SOAP service.
            'omnibus.action.get_slip' => static fn (Config $c) => self::api2($c) && !$c->bool('legacy_labels') ? null : new GetSlipAction($c->bool('download_labels')),
        ]);
    }

    /** Whether API2's three credentials are all there; one or two of them is a mistake said at once. */
    private static function api2(Config $c): bool
    {
        $given = array_filter(['login', 'password', 'customer_id'], static fn (string $k) => '' !== (string) $c->string($k));
        if ($given && 3 !== \count($given)) {
            $c->validateNotEmpty(['login', 'password', 'customer_id']);
        }

        return 3 === \count($given);
    }
}
