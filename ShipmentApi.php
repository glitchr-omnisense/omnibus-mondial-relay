<?php

namespace Omnibus\MondialRelay;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Omnibus\Http\Throttle;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mondial Relay's API2, the "dual carrier" shipment API (Web Service Dual
 * Carrier V-2.7.1, June 2024): one REST method that books a shipment and
 * gives its label, an XML ShipmentCreationRequest POSTed to /api/shipment.
 * Its credentials - a login, a password and a customer id, from Connect's
 * "Configuration des API", API V2.0 - travel in the request's Context, not
 * the API1 brand code and private key.
 *
 * The answer repeats the request's Context, password included: no part of an
 * answer is ever quoted in a failure.
 */
final class ShipmentApi
{
    public const ENDPOINT = 'https://connect-api.mondialrelay.com/api/shipment';
    public const SANDBOX_ENDPOINT = 'https://connect-api-sandbox.mondialrelay.com/api/shipment';

    /** The namespace of the specification's request example (its XSD was not readable on 2026-11-11). */
    public const REQUEST_NS = 'http://www.example.org/Request';

    /**
     * API2 takes no idempotency key and OrderNo is free data, not checked for duplicates (as far as
     * the specification says): a request lost after it left may have booked the shipment.
     */
    public const UNCERTAIN = 'The shipment may have been booked all the same: look for its reference in Connect before booking it again.';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $login,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $customerId,
        private readonly string $endpoint = self::ENDPOINT,
        /** The culture the request is processed and the label written in: fr-FR, nl-BE... */
        private readonly string $culture = 'fr-FR',
        /** The waits the carrier asks for (omnisense/contracts' Backoff): no call while one lasts, a 429 a RateLimitedException, never retried */
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /**
     * One shipment booked: its ShipmentNumber and its label's output (a PDF's URL, or base64 ZPL).
     *
     * @param string $shipment the <Shipment> element, already written
     *
     * @return array{number: string, output: string}
     */
    public function create(string $shipment, string $outputType, string $outputFormat): array
    {
        $request = '<?xml version="1.0" encoding="utf-8"?>'
            .'<ShipmentCreationRequest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns="'.self::REQUEST_NS.'">'
            .'<Context>'.self::element('Login', $this->login).self::element('Password', $this->password).self::element('CustomerId', $this->customerId)
            .self::element('Culture', $this->culture).'<VersionAPI>1.0</VersionAPI></Context>'
            .'<OutputOptions>'.self::element('OutputFormat', $outputFormat).self::element('OutputType', $outputType).'</OutputOptions>'
            .'<ShipmentsList>'.$shipment.'</ShipmentsList>'
            .'</ShipmentCreationRequest>';

        try {
            Throttle::before($this->backoff);
            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => ['Accept' => 'application/xml', 'Content-Type' => 'text/xml'],
                'body' => $request,
            ]);
            Throttle::after($this->backoff, 'mondial_relay', $response->getStatusCode(), $response->getHeaders(false));
            $xml = $response->getContent();
        } catch (ExceptionInterface $e) {
            $secrets = [$this->password, $this->login];

            // A timeout or a 5xx may come after the shipment was booked: say so, the caller looks before booking again.
            $uncertain = $e instanceof TransportExceptionInterface || $e instanceof ServerExceptionInterface;

            throw Redaction::withoutArguments(new CarrierException('mondial_relay', Redaction::text($e->getMessage(), $secrets).($uncertain ? ' '.self::UNCERTAIN : ''), $uncertain ? 'uncertain' : null, Redaction::copy($e, $secrets)));
        }

        $document = self::parse($xml);
        if (null === $document || 'ShipmentCreationResponse' !== $document->getName()) {
            throw Redaction::withoutArguments(new CarrierException('mondial_relay', 'Unexpected answer to the shipment creation: not a ShipmentCreationResponse.'));
        }

        $errors = [];
        foreach ($document->xpath('//*[local-name()="StatusList"]/*[local-name()="Status"]') ?: [] as $status) {
            $code = self::field($status, 'Code');
            $level = self::field($status, 'Level');
            // Warnings ("Statement ignored") do not stop the booking.
            if ('' !== $code && !str_contains(strtolower($level), 'warning')) {
                $errors[$code] = self::field($status, 'Message');
            }
        }

        $shipments = $document->xpath('//*[local-name()="ShipmentsList"]/*[local-name()="Shipment"]') ?: [];
        $number = isset($shipments[0]) ? trim((string) $shipments[0]['ShipmentNumber']) : '';
        $outputs = isset($shipments[0]) ? ($shipments[0]->xpath('.//*[local-name()="Label"]/*[local-name()="Output"]') ?: []) : [];
        $output = isset($outputs[0]) ? trim((string) $outputs[0]) : '';

        if ($errors || '' === $number) {
            $code = (string) array_key_first($errors);
            $message = $errors ? (('' !== $errors[$code] ? $errors[$code] : 'Error '.$code).(\count($errors) > 1 ? \sprintf(' (and %d more: %s)', \count($errors) - 1, implode(', ', \array_slice(array_keys($errors), 1))) : '')) : 'Mondial Relay booked no shipment.';

            throw Redaction::withoutArguments(new CarrierException('mondial_relay', $message, '' !== $code ? $code : null));
        }

        return ['number' => $number, 'output' => $output];
    }

    /**
     * The answer as XML. The specification's example declares encoding="utf-16" over UTF-8 bytes
     * (the service writes UTF-8 without BOM, it says): such a declaration is read as UTF-8.
     */
    private static function parse(string $xml): ?\SimpleXMLElement
    {
        if (!str_starts_with($xml, "\xFF\xFE") && !str_starts_with($xml, "\xFE\xFF")) {
            $xml = (string) preg_replace('/^(<\?xml[^>]*encoding=["\'])utf-16(["\'])/i', '$1utf-8$2', ltrim($xml, "\xEF\xBB\xBF"));
        }
        $document = @simplexml_load_string($xml);

        return false === $document ? null : $document;
    }

    /** A Status's Code, Level or Message: an attribute, or a child element, whichever the answer uses. */
    private static function field(\SimpleXMLElement $status, string $name): string
    {
        foreach ([$name, lcfirst($name)] as $key) {
            if (isset($status[$key])) {
                return trim((string) $status[$key]);
            }
            $children = $status->xpath('./*[local-name()="'.$key.'"]') ?: [];
            if (isset($children[0])) {
                return trim((string) $children[0]);
            }
        }

        return '';
    }

    public static function element(string $name, string $value): string
    {
        return \sprintf('<%1$s>%2$s</%1$s>', $name, htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES));
    }

    public static function attribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES);
    }
}
