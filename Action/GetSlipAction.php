<?php

namespace Omnibus\MondialRelay\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Label;
use Omnibus\Request\GetSlip;
use Omnibus\Request\Request;
use Omnibus\MondialRelay\Api;

/**
 * WSI3_GetEtiquettes: a booked label again, 10x15 (thermal printers), else A4.
 * Deprecated with the SOAP labels: Mondial Relay stopped it (Web Service
 * V-5.11, April 2024) for the accounts opened since 2024-12-23, and API2 has
 * no reprint. The gateway offers it only while labels come from the SOAP
 * service (no API2 credentials, or legacy_labels); an API2 label's url is
 * where it is printed again.
 */
final class GetSlipAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(private readonly bool $download = false)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        // isset: the gateway probes support with a request built without its constructor
        return $request instanceof GetSlip && (!isset($request->format) || Label::PDF === $request->format);
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetSlip);
        $result = $this->api->call('WSI3_GetEtiquettes', ['Expeditions' => $request->trackingNumber, 'Langue' => 'FR']);
        $path = trim((string) $result->URL_PDF_10x15) ?: trim((string) $result->URL_PDF_A4);
        $url = Api::SITE.$path;

        $request->setResult(new Label(
            'mondial_relay',
            $request->trackingNumber,
            $this->download ? $this->api->download($url) : null,
            url: $url,
            trackingUrl: TrackingAction::publicUrl($request->trackingNumber, $this->api->enseigne()),
        ));
    }
}
