<?php

namespace Omnibus\MondialRelay\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;
use Omnibus\MondialRelay\Api;

/**
 * WSI4_PointRelais_Recherche: the relay points around a postcode, nearest
 * first, that take the parcel's weight. A point's id is "<country>-<number>"
 * (FR-066974): what ShippingAction books to.
 */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const DAYS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $result = $this->api->call('WSI4_PointRelais_Recherche', [
            'Pays' => strtoupper($request->near->country),
            'NumPointRelais' => '',
            'Ville' => Api::text($request->near->city, 25),
            'CP' => $request->near->postcode,
            'Latitude' => '',
            'Longitude' => '',
            'Taille' => '',
            'Poids' => $request->parcel ? max(15, $request->parcel->weight) : '',
            'Action' => '24R',
            'DelaiEnvoi' => '0',
            'RayonRecherche' => '',
            'TypeActivite' => '',
            'NACE' => '',
            'NombreResultats' => min(30, max(1, $request->limit)),
        ]);

        $points = [];
        foreach ($result->PointsRelais->PointRelais_Details ?? [] as $point) {
            $points[] = new PickupPoint(
                'mondial_relay',
                trim((string) $point->Pays).'-'.trim((string) $point->Num),
                trim((string) $point->LgAdr1),
                new Address(
                    trim((string) $point->LgAdr1),
                    array_values(array_filter([trim((string) $point->LgAdr3), trim((string) $point->LgAdr4)])),
                    trim((string) $point->CP),
                    trim((string) $point->Ville),
                    trim((string) $point->Pays),
                    trim((string) $point->LgAdr2) ?: null,
                ),
                self::coordinate((string) $point->Latitude),
                self::coordinate((string) $point->Longitude),
                self::hours($point),
                '' !== trim((string) $point->Distance) ? (int) $point->Distance : null,
            );
        }

        $request->setResult(\array_slice($points, 0, max(1, $request->limit))); // no more than asked, whatever the service sends
    }

    private static function coordinate(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Horaires_Lundi..Dimanche: four <string>s, HHMM - morning open/close,
     * afternoon open/close - "0000" where the point is shut.
     *
     * @return array<int, list<array{string, string}>>
     */
    private static function hours(\SimpleXMLElement $point): array
    {
        $week = [];
        foreach (self::DAYS as $day => $name) {
            $times = array_map(static fn ($t) => trim((string) $t), iterator_to_array($point->{'Horaires_'.$name}->string ?? [], false));
            $slots = [];
            foreach (array_chunk($times, 2) as $pair) {
                if (2 === \count($pair) && '0000' !== $pair[0] && '' !== $pair[0]) {
                    $slots[] = [substr($pair[0], 0, 2).':'.substr($pair[0], 2), substr($pair[1], 0, 2).':'.substr($pair[1], 2)];
                }
            }
            if ($slots) {
                $week[$day] = $slots;
            }
        }

        return $week;
    }
}
