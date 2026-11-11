<?php

namespace Omnibus\MondialRelay;

use Omnibus\Config;
use Omnibus\GatewayFactory;
use Omnibus\MondialRelay\Action\GetSlipAction;
use Omnibus\MondialRelay\Action\PickupAction;
use Omnibus\MondialRelay\Action\ShippingAction;
use Omnibus\MondialRelay\Action\TrackingAction;
use Omnisense\Contracts\Policy\Scope;
use Omnisense\Contracts\Policy\UsagePolicy;
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
 *
 * Its usage policy (policy.json) counts each parcel's tracking requests apart
 * (6 a day): TrackingAction counts them itself, under "trackParcel", the
 * tracking number named, while the gateway's guard counts "tracking".
 */
final class MondialRelayGatewayFactory extends GatewayFactory
{
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    protected function populateConfig(Config $config): void
    {
        if ($config->get('sandbox')) {
            $config->defaults(['enseigne' => Api::TEST_ENSEIGNE, 'private_key' => Api::TEST_PRIVATE_KEY, 'shipment_endpoint' => ShipmentApi::SANDBOX_ENDPOINT]);
        }
        $config->defaults([
            'omnibus.factory_name' => 'mondial_relay',
            'omnibus.factory_title' => 'Mondial Relay',
            'omnibus.policy' => self::usagePolicy(),
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
            'omnibus.action.tracking' => static fn (Config $c) => new TrackingAction(self::countsPerResource() ? $c->guard(self::usagePolicy())->perResource() : null),
            // WSI3_GetEtiquettes: stopped for the accounts opened since 2024-12-23, and API2 has no reprint (a label's
            // url is its reprint). Kept where the labels still come from the SOAP service.
            'omnibus.action.get_slip' => static fn (Config $c) => self::api2($c) && !$c->bool('legacy_labels') ? null : new GetSlipAction($c->bool('download_labels')),
        ]);
    }

    /**
     * Its usage policy, as policy.json declares it. Under an omnisense/contracts
     * older than its resource scope (before 2f78f0e), which refuses the file's
     * limit per parcel, the policy without it: what it declared before.
     */
    public static function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= self::readPolicy();
    }

    private static function readPolicy(): UsagePolicy
    {
        if (self::countsPerResource()) {
            return UsagePolicy::fromFile(self::POLICY);
        }
        $data = json_decode((string) file_get_contents(self::POLICY), true, 64, \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_array($data['rateLimits'] ?? null));
        $limits = array_values(array_filter($data['rateLimits'], static fn (mixed $limit): bool => !\is_array($limit) || 'resource' !== ($limit['scope'] ?? null)));
        $data['rateLimits'] = [] === $limits ? null : $limits;

        /** @var array<string, mixed> $data */
        return UsagePolicy::fromArray($data);
    }

    /** Whether the kernel counts limits per resource (omnisense/contracts 2f78f0e and later). */
    private static function countsPerResource(): bool
    {
        return \defined(Scope::class.'::Resource');
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
