<?php

namespace Omnibus\MondialRelay;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Omnibus\Http\Throttle;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mondial Relay's API1, the SOAP web services (SOAP 1.1, Web_Services.asmx),
 * spoken over plain HTTP: every call is its parameters in the documented
 * order, signed with Security = MD5(the values one after the other + the
 * private key), in capitals. API1 is the maintained service for the relay
 * point search and tracking; its shipment and label methods are stopped
 * (Web Service V-5.11, April 2024): labels go through API2 (ShipmentApi),
 * reached from here by shipments().
 */
final class Api
{
    public const ENDPOINT = 'https://api.mondialrelay.com/Web_Services.asmx';
    public const SITE = 'https://www.mondialrelay.com';
    private const NS = 'http://www.mondialrelay.fr/webservice/';

    /**
     * Mondial Relay's public test brand: works on the live endpoint, books
     * nothing. Published by Mondial Relay in its integration documentation
     * (Web Service V-5.14, January 2025: "Test credentials"; the key changed
     * from PrivateK in V-5.12, September 2024), the same for everyone: test
     * identifiers, not a secret.
     */
    public const TEST_ENSEIGNE = 'BDTEST13';
    public const TEST_PRIVATE_KEY = 'TestAPI1key';

    /** The STAT codes, as Web Service V-5.14 (January 2025) words them; 80 to 83 are tracking statuses. */
    private const ERRORS = [
        '1' => 'Incorrect merchant',
        '2' => 'Merchant number empty',
        '3' => 'Incorrect merchant account number',
        '5' => 'Incorrect merchant shipment reference',
        '7' => 'Incorrect consignee reference',
        '8' => 'Incorrect password or hash',
        '9' => 'Unknown or not unique city',
        '10' => 'Incorrect type of collection',
        '11' => 'Incorrect collection Point Relais number',
        '12' => 'Incorrect collection Point Relais country',
        '13' => 'Incorrect type of delivery',
        '14' => 'Incorrect delivery Point Relais number',
        '15' => 'Incorrect delivery Point Relais country',
        '16' => 'Incorrect country code',
        '17' => 'Incorrect address',
        '18' => 'Incorrect city',
        '19' => 'Incorrect postal code',
        '20' => 'Incorrect parcel weight',
        '21' => 'Incorrect developed length (length + height)',
        '22' => 'Incorrect parcel size',
        '24' => 'Incorrect shipment number',
        '25' => 'Insufficient account credit',
        '27' => 'Incorrect mode of collection or delivery',
        '28' => 'Incorrect mode of collection',
        '29' => 'Incorrect mode of delivery',
        '30' => 'Incorrect address (L1)',
        '31' => 'Incorrect address (L2)',
        '33' => 'Incorrect address (L3)',
        '34' => 'Incorrect address (L4)',
        '35' => 'Incorrect city',
        '36' => 'Incorrect zipcode',
        '37' => 'Incorrect country',
        '38' => 'Incorrect phone number',
        '39' => 'Incorrect e-mail',
        '40' => 'Missing parameters',
        '47' => 'Incorrect number of parcels',
        '48' => 'Multi-parcel not permitted at Point Relais',
        '49' => 'Incorrect action',
        '63' => 'Incorrect insurance',
        '67' => 'Incorrect latitude',
        '68' => 'Incorrect longitude',
        '69' => 'Incorrect merchant code',
        '70' => 'Incorrect Point Relais number',
        '74' => 'Incorrect language',
        '78' => 'Incorrect country of collection',
        '79' => 'Incorrect country of delivery',
        '91' => 'PUDO nature not allowed for the account',
        '92' => "The Point Relais country code and the consignee's country code are different, or insufficient funds",
        '93' => 'No information given by the sorting plan',
        '94' => 'Unknown parcel',
        '95' => 'Merchant account not activated',
        '97' => 'Incorrect security key',
        '98' => 'Generic error (incorrect parameters)',
        '99' => 'Generic error of service system',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $enseigne,
        #[\SensitiveParameter] private readonly string $privateKey,
        private readonly string $endpoint = self::ENDPOINT,
        /** The waits the carrier asks for (omnisense/contracts' Backoff): no call while one lasts, a 429 a RateLimitedException, never retried */
        private readonly ?Backoff $backoff = null,
        /** API2, for labels: null when its credentials (login, password, customer_id) are not configured */
        private readonly ?ShipmentApi $shipments = null,
    ) {
    }

    public function shipments(): ?ShipmentApi
    {
        return $this->shipments;
    }

    public function enseigne(): string
    {
        return $this->enseigne;
    }

    /**
     * @param array<string, scalar|null> $params in the method's order, Enseigne and Security left out
     * @param string[]                   $accept STAT codes that are answers, not errors (tracing: 80-83)
     */
    public function call(string $method, array $params, array $accept = ['0']): \SimpleXMLElement
    {
        $params = ['Enseigne' => $this->enseigne] + array_map(static fn ($v) => (string) $v, $params);
        $params['Security'] = self::security($params, $this->privateKey);

        $body = '';
        foreach ($params as $name => $value) {
            $body .= \sprintf('<%1$s>%2$s</%1$s>', $name, htmlspecialchars($value, \ENT_XML1));
        }
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .\sprintf('<soap:Body><%1$s xmlns="%2$s">%3$s</%1$s></soap:Body></soap:Envelope>', $method, self::NS, $body);

        try {
            Throttle::before($this->backoff);
            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"'.self::NS.$method.'"'],
                'body' => $envelope,
            ]);
            Throttle::after($this->backoff, 'mondial_relay', $response->getStatusCode(), $response->getHeaders(false));
            $xml = $response->getContent();
        } catch (ExceptionInterface $e) {
            $secrets = $this->secrets($params['Security']);

            throw Redaction::withoutArguments(new CarrierException('mondial_relay', Redaction::text($e->getMessage(), $secrets), previous: Redaction::copy($e, $secrets)));
        }

        $document = @simplexml_load_string($xml);
        if (false === $document || 'Envelope' !== $document->getName()) {
            throw Redaction::withoutArguments(new CarrierException('mondial_relay', \sprintf('Unexpected answer to %s: not a SOAP envelope.', $method)));
        }
        $result = $document->children('http://schemas.xmlsoap.org/soap/envelope/')->Body->children(self::NS)->{$method.'Response'}->{$method.'Result'};
        if (null === $result || !$result->count()) {
            throw Redaction::withoutArguments(new CarrierException('mondial_relay', \sprintf('Unexpected answer to %s.', $method)));
        }
        $stat = (string) $result->STAT;
        if (!\in_array($stat, $accept, true)) {
            throw Redaction::withoutArguments(new CarrierException('mondial_relay', self::ERRORS[$stat] ?? 'STAT '.$stat, $stat));
        }

        return $result;
    }

    /** A document the services link to (a label's PDF). */
    public function download(string $url): string
    {
        try {
            return $this->http->request('GET', $url)->getContent();
        } catch (ExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('mondial_relay', Redaction::text($e->getMessage(), $secrets), previous: Redaction::copy($e, $secrets)));
        }
    }

    /** @param array<string, string> $params */
    public static function security(array $params, string $privateKey): string
    {
        return strtoupper(md5(implode('', $params).$privateKey));
    }

    /**
     * Mondial Relay takes addresses in capitals, without accents, and cuts
     * each field at a length: "Émile Zola" -> "EMILE ZOLA".
     */
    public static function text(?string $value, int $length = 32): string
    {
        $value = (string) $value;
        $ascii = \function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII', $value)
            : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $clean = preg_replace('/[^A-Z0-9 \'.,\/-]/', ' ', strtoupper((string) $ascii));

        return trim(substr(preg_replace('/\s+/', ' ', (string) $clean), 0, $length));
    }

    /** A phone as the services take it: +33612345678 or 0612345678. */
    public static function phone(?string $phone): string
    {
        return preg_replace('/(?!^\+)[^0-9]/', '', (string) $phone);
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->privateKey, ...$more];
    }
}
