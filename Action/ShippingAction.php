<?php

namespace Omnibus\MondialRelay\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\Http\Redaction;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\Shipment;
use Omnibus\MondialRelay\Api;
use Omnibus\MondialRelay\ShipmentApi;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/**
 * Books the shipment - to a relay point when it has one (delivery mode 24R),
 * else to the door (HOM, or the service asked) - and gives the shipment
 * number and its label, through API2's shipment creation (ShipmentApi): one
 * call, the number and the label together.
 *
 * With legacy_labels and no API2 credentials, the SOAP WSI2_CreationEtiquette
 * instead: no longer maintained, closed to the accounts opened since
 * 2024-12-23, kept for the transition only.
 */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** label_format => [OutputType, OutputFormat, the Label's media type] (Dual Carrier V-2.7.1, output format compatibility) */
    private const FORMATS = [
        'A4' => ['PdfUrl', 'A4', Label::PDF],
        'A5' => ['PdfUrl', 'A5', Label::PDF],
        '10X15' => ['PdfUrl', '10x15', Label::PDF],
        'ZPL' => ['ZplCode', 'Generic_ZPL_10x15_200dpi', Label::ZPL],
    ];

    public function __construct(
        private readonly string $collection = 'CCC',
        private readonly bool $download = false,
        private readonly string $labelFormat = '10x15',
        private readonly bool $legacyLabels = false,
    ) {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $shipments = $this->api->shipments();
        if (null !== $shipments) {
            $request->setResult($this->book($shipments, $request->shipment));

            return;
        }
        if (!$this->legacyLabels) {
            throw Redaction::withoutArguments(new InvalidConfigException(self::MISSING));
        }
        @trigger_error('omnibus/mondial-relay: labels through WSI2_CreationEtiquette (legacy_labels) are deprecated, Mondial Relay no longer maintains it; set login, password and customer_id for API2.', \E_USER_DEPRECATED);
        $request->setResult($this->legacy($request->shipment));
    }

    /** What a gateway configured with the API1 credentials alone answers to ship(). */
    public const MISSING = 'The "mondial_relay" gateway books labels through Mondial Relay\'s API2: set the options login, password and customer_id (Connect, Administration, Configuration des API, API V2.0). WSI2_CreationEtiquette, which enseigne and private_key reached, is no longer maintained; legacy_labels: true keeps it for an account opened before 2024-12-23, for the transition.';

    private function book(ShipmentApi $api, Shipment $shipment): Label
    {
        $format = strtoupper(self::option($shipment, 'label_format', $this->labelFormat));
        [$type, $size, $media] = self::FORMATS[$format] ?? throw Redaction::withoutArguments(new InvalidConfigException(\sprintf('The "mondial_relay" gateway prints labels as A4, A5, 10x15 or ZPL, not "%s".', $format)));
        $mode = $shipment->service ?? ($shipment->pickupPoint ? '24R' : 'HOM');
        $location = $shipment->pickupPoint ? implode('-', self::relay($shipment->pickupPoint, $shipment->recipient->country)) : '';

        $options = '';
        foreach (array_filter(['ASS' => self::option($shipment, 'insurance', ''), 'LNG' => self::option($shipment, 'language', '')], static fn (string $v) => '' !== $v) as $key => $value) {
            $options .= \sprintf('<Option Key="%s" Value="%s" />', $key, ShipmentApi::attribute($value));
        }
        $parcels = '';
        foreach ($shipment->parcels as $parcel) {
            $parcels .= '<Parcel>'
                .ShipmentApi::element('Content', Api::text(self::option($shipment, 'content', ''), 40))
                .($parcel->length ? \sprintf('<Length Value="%d" Unit="cm" />', $parcel->length) : '')
                .($parcel->width ? \sprintf('<Width Value="%d" Unit="cm" />', $parcel->width) : '')
                .($parcel->height ? \sprintf('<Depth Value="%d" Unit="cm" />', $parcel->height) : '')
                .\sprintf('<Weight Value="%d" Unit="gr" />', max(10, $parcel->weight))
                .'</Parcel>';
        }

        $xml = '<Shipment>'
            .($options ? '<Options>'.$options.'</Options>' : '')
            .ShipmentApi::element('OrderNo', self::code($shipment->reference, 15, '_-'))
            .ShipmentApi::element('CustomerNo', self::code(self::option($shipment, 'customer_no', ''), 9))
            .ShipmentApi::element('ParcelCount', (string) \count($shipment->parcels))
            .\sprintf('<DeliveryMode Mode="%s" Location="%s" />', ShipmentApi::attribute($mode), ShipmentApi::attribute($location))
            .\sprintf('<CollectionMode Mode="%s" Location="" />', ShipmentApi::attribute($this->collection))
            .'<Parcels>'.$parcels.'</Parcels>'
            .ShipmentApi::element('DeliveryInstruction', Api::text(self::option($shipment, 'instructions', ''), 30))
            .'<Sender>'.self::address($shipment->sender).'</Sender>'
            .'<Recipient>'.self::address($shipment->recipient).'</Recipient>'
            .'</Shipment>';

        $booked = $api->create($xml, $type, $size);
        $number = $booked['number'];
        $trackingUrl = TrackingAction::publicUrl($number, $this->api->enseigne());

        if ('ZplCode' === $type) {
            $zpl = base64_decode($booked['output'], true);

            return new Label('mondial_relay', $number, false !== $zpl && '' !== $zpl ? $zpl : null, Label::ZPL, trackingUrl: $trackingUrl);
        }

        $url = false !== filter_var($booked['output'], \FILTER_VALIDATE_URL) ? $booked['output'] : null;
        $content = null;
        if ($this->download && null !== $url) {
            try {
                $content = $this->api->download($url);
            } catch (CarrierException|UsageLimitReachedException) {
                // The shipment is booked: the label keeps its link (Label::$url) to fetch the PDF from later.
            }
        }

        return new Label('mondial_relay', $number, $content, $media, $url, $trackingUrl);
    }

    /** WSI2_CreationEtiquette (SOAP, API1): the transition's path, for the accounts it still serves. */
    private function legacy(Shipment $shipment): Label
    {
        [$relayCountry, $relay] = $shipment->pickupPoint ? self::relay($shipment->pickupPoint, $shipment->recipient->country) : ['', ''];
        $parcel = $shipment->parcels[0];

        $result = $this->api->call('WSI2_CreationEtiquette', [
            'ModeCol' => $this->collection,
            'ModeLiv' => $shipment->service ?? ('' !== $relay ? '24R' : 'HOM'),
            'NDossier' => Api::text($shipment->reference, 15),
            'NClient' => '',
            ...self::party('Expe', $shipment->sender),
            ...self::party('Dest', $shipment->recipient),
            'Poids' => max(15, $shipment->weight()),
            'Longueur' => $parcel->length ?? '',
            'Taille' => '',
            'NbColis' => \count($shipment->parcels),
            'CRT_Valeur' => '0',
            'CRT_Devise' => '',
            'Exp_Valeur' => '',
            'Exp_Devise' => '',
            'COL_Rel_Pays' => '',
            'COL_Rel' => '',
            'LIV_Rel_Pays' => $relayCountry,
            'LIV_Rel' => $relay,
            'TAvisage' => '',
            'TReprise' => '',
            'Montage' => '',
            'TRDV' => '',
            'Assurance' => self::option($shipment, 'insurance', ''),
            'Instructions' => Api::text(self::option($shipment, 'instructions', ''), 31),
        ]);

        $number = trim((string) $result->ExpeditionNum);
        $url = Api::SITE.trim((string) $result->URL_Etiquette);
        $content = null;
        if ($this->download) {
            try {
                $content = $this->api->download($url);
            } catch (CarrierException|UsageLimitReachedException) {
                // Booked: the label keeps its link.
            }
        }

        return new Label('mondial_relay', $number, $content, url: $url, trackingUrl: TrackingAction::publicUrl($number, $this->api->enseigne()));
    }

    /**
     * "FR-066974", or "066974" in the recipient's country.
     *
     * @return array{string, string}
     */
    private static function relay(string $id, string $country): array
    {
        $parts = explode('-', $id, 2);

        return 2 === \count($parts) ? [strtoupper($parts[0]), $parts[1]] : [strtoupper($country), $id];
    }

    /** A shipment option as text: empty when it is not given, or not a scalar. */
    private static function option(Shipment $shipment, string $name, string $default = ''): string
    {
        $value = $shipment->option($name, $default);

        return \is_scalar($value) ? (string) $value : $default;
    }

    /** OrderNo, CustomerNo: capitals and digits (and the characters allowed), cut to length. */
    private static function code(?string $value, int $length, string $also = ''): string
    {
        return substr((string) preg_replace('/[^0-9A-Z'.preg_quote($also, '/').']/', '', Api::text($value, 64)), 0, $length);
    }

    /** An API2 <Address>: the name as AddressAdd1, the company as AddressAdd2, two street lines. */
    private static function address(Address $address): string
    {
        $phone = Api::phone($address->phone);

        return '<Address>'
            .ShipmentApi::element('Streetname', Api::text($address->line(0), 40))
            .ShipmentApi::element('CountryCode', strtoupper($address->country))
            .ShipmentApi::element('PostCode', strtoupper(trim($address->postcode)))
            .ShipmentApi::element('City', Api::text($address->city, 30))
            .ShipmentApi::element('AddressAdd1', Api::text($address->name, 30))
            .ShipmentApi::element('AddressAdd2', Api::text($address->company, 30))
            .ShipmentApi::element('AddressAdd3', Api::text($address->line(1), 30))
            .ShipmentApi::element('PhoneNo', $phone)
            .ShipmentApi::element('MobileNo', $phone)
            .ShipmentApi::element('Email', (string) $address->email)
            .'</Address>';
    }

    /** @return array<string, string> Expe_Langage, Expe_Ad1..4, Expe_Ville... in the documented order */
    private static function party(string $prefix, Address $address): array
    {
        return [
            $prefix.'_Langage' => 'FR',
            $prefix.'_Ad1' => Api::text($address->name),
            $prefix.'_Ad2' => Api::text($address->company),
            $prefix.'_Ad3' => Api::text($address->line(0)),
            $prefix.'_Ad4' => Api::text($address->line(1)),
            $prefix.'_Ville' => Api::text($address->city, 26),
            $prefix.'_CP' => $address->postcode,
            $prefix.'_Pays' => strtoupper($address->country),
            $prefix.'_Tel1' => Api::phone($address->phone),
            $prefix.'_Tel2' => '',
            $prefix.'_Mail' => (string) $address->email,
        ];
    }
}
