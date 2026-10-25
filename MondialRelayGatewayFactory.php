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
 *     enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%'       # the brand code of the contract (8 characters)
 *     private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%'
 *     sandbox: true                                   # Mondial Relay's public test brand, no contract needed
 *     collection: CCC                                 # CCC: collected at the shop; REL: dropped at a relay
 *     download_labels: false                          # true: the PDF inline in the Label, not only its URL
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
            $config->defaults(['enseigne' => Api::TEST_ENSEIGNE, 'private_key' => Api::TEST_PRIVATE_KEY]);
        }
        $config->defaults([
            'omnibus.factory_name' => 'mondial_relay',
            'omnibus.factory_title' => 'Mondial Relay',
            'omnibus.required_options' => ['enseigne', 'private_key'],
            'endpoint' => Api::ENDPOINT,
            'collection' => 'CCC',
            'download_labels' => false,
            'omnibus.api' => function (Config $c) {
                return new Api($this->http ?? HttpClient::create(), (string) $c['enseigne'], (string) $c['private_key'], (string) $c['endpoint']);
            },
            'omnibus.action.pickup' => new PickupAction(),
            'omnibus.action.shipping' => static fn (Config $c) => new ShippingAction((string) $c['collection'], (bool) $c['download_labels']),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.get_slip' => static fn (Config $c) => new GetSlipAction((bool) $c['download_labels']),
        ]);
    }
}
