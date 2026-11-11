<?php

namespace Omnibus\MondialRelay\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;
use Omnibus\MondialRelay\Api;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * WSI2_TracingColisDetaille (API1, still the maintained tracking service:
 * Presentation of WebServices V-1.2, February 2026). Mondial Relay asks that a
 * shipment be tracked 4 to 6 times a day at most, and no more once delivered
 * or returned: policy.json declares 6 a day per parcel, which the guard the
 * factory gives counts under "trackParcel", the tracking number named (a 7th
 * request that day is a UsageLimitReachedException, never sent); after
 * delivery, the caller stops. Its STAT is the parcel's status - 80 registered,
 * 81 on its way, 82 delivered, 83 an anomaly - and each step has a sentence,
 * a date and a place.
 */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const STATUS = [
        '80' => TrackingStatus::PENDING,
        '81' => TrackingStatus::IN_TRANSIT,
        '82' => TrackingStatus::DELIVERED,
        '83' => TrackingStatus::EXCEPTION,
    ];

    /** @param ?PolicyGuard $parcels the policy's limits per parcel (PolicyGuard::perResource()); null: none counted here */
    public function __construct(private readonly ?PolicyGuard $parcels = null)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $track = fn (): \SimpleXMLElement => $this->api->call('WSI2_TracingColisDetaille', [
            'Expedition' => $request->trackingNumber,
            'Langue' => strtoupper(substr($request->locale, 0, 2)),
        ], array_map('strval', array_keys(self::STATUS)));
        $result = null === $this->parcels ? $track() : $this->parcels->call('trackParcel', $track, ['resource' => $request->trackingNumber]);

        $status = self::STATUS[(string) $result->STAT];
        $events = [];
        foreach ($result->Tracing->ret_WSI2_sub_TracingColisDetaille ?? [] as $step) {
            $label = trim((string) $step->Libelle);
            $at = self::date(trim((string) $step->Date), trim((string) $step->Heure));
            if ('' === $label || null === $at) {
                continue;
            }
            $events[] = new TrackingEvent($at, self::stepStatus($label, $status), $label, trim((string) $step->Emplacement) ?: null);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        // "On its way" and the last step says it waits at the relay: say so.
        if (TrackingStatus::IN_TRANSIT === $status && $events && TrackingStatus::AVAILABLE_FOR_PICKUP === end($events)->status) {
            $status = TrackingStatus::AVAILABLE_FOR_PICKUP;
        }

        $request->setResult(new TrackingModel('mondial_relay', $request->trackingNumber, $status, $events));
    }

    /** The public tracking page, for the customer. */
    public static function publicUrl(string $number, string $enseigne): string
    {
        return 'https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition='.rawurlencode($number).'&codeMarque='.rawurlencode(substr($enseigne, 0, 2));
    }

    private static function stepStatus(string $label, TrackingStatus $overall): TrackingStatus
    {
        $text = strtoupper($label);

        return match (true) {
            str_contains($text, 'LIVR') && !str_contains($text, 'EN COURS') => TrackingStatus::DELIVERED,
            str_contains($text, 'DISPONIBLE') => TrackingStatus::AVAILABLE_FOR_PICKUP,
            str_contains($text, 'RETOUR') => TrackingStatus::RETURNED,
            str_contains($text, 'ANOMALIE') || str_contains($text, 'INCIDENT') => TrackingStatus::EXCEPTION,
            TrackingStatus::PENDING === $overall => TrackingStatus::PENDING,
            default => TrackingStatus::IN_TRANSIT,
        };
    }

    /** "dd/mm/yy" or "dd/mm/yyyy", and "HH:MM" - French time. */
    private static function date(string $date, string $time): ?\DateTimeImmutable
    {
        // "Y" would read "26" as the year 26: the format follows the digits.
        $year = preg_match('#^\d{2}/\d{2}/\d{4}$#', $date) ? 'Y' : 'y';
        $format = 'd/m/'.$year.('' !== $time ? ' H:i' : '');
        $at = \DateTimeImmutable::createFromFormat('!'.$format, trim($date.' '.$time), new \DateTimeZone('Europe/Paris'));
        if ($at && !\DateTimeImmutable::getLastErrors()) {
            return $at;
        }

        return null;
    }
}
