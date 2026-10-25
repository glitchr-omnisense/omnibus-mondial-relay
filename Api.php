<?php

namespace Omnibus\MondialRelay;

use Omnibus\Exception\CarrierException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mondial Relay's web services (SOAP 1.1, Web_Services.asmx), spoken over
 * plain HTTP: every call is its parameters in the documented order, signed
 * with Security = MD5(the values one after the other + the private key),
 * in capitals.
 */
final class Api
{
    public const ENDPOINT = 'https://api.mondialrelay.com/Web_Services.asmx';
    public const SITE = 'https://www.mondialrelay.com';
    private const NS = 'http://www.mondialrelay.fr/webservice/';

    /**
     * Mondial Relay's public test brand: works on the live endpoint, books
     * nothing. Published by Mondial Relay in its integration documentation,
     * the same for everyone: test identifiers, not a secret.
     */
    public const TEST_ENSEIGNE = 'BDTEST13';
    public const TEST_PRIVATE_KEY = 'PrivateK';

    /** The STAT codes worth a sentence; the rest are numbered in the documentation. */
    private const ERRORS = [
        '1' => 'Enseigne invalide',
        '2' => "Numéro d'enseigne vide ou inexistant",
        '8' => 'Mot de passe ou hachage invalide',
        '9' => 'Ville non reconnue ou non unique',
        '10' => 'Type de collecte invalide',
        '12' => 'Type de livraison invalide',
        '20' => 'Poids du colis invalide',
        '24' => "Numéro d'expédition ou de suivi invalide",
        '30' => 'Adresse (L1) invalide',
        '35' => 'Code postal invalide',
        '40' => 'Pays invalide',
        '44' => 'Point relais de livraison invalide',
        '94' => 'Colis inexistant',
        '95' => 'Compte enseigne non activé',
        '97' => 'Clé de sécurité invalide',
        '99' => 'Erreur générique du service',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $enseigne,
        private readonly string $privateKey,
        private readonly string $endpoint = self::ENDPOINT,
    ) {
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
            $xml = $this->http->request('POST', $this->endpoint, [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"'.self::NS.$method.'"'],
                'body' => $envelope,
            ])->getContent();
        } catch (ExceptionInterface $e) {
            throw new CarrierException('mondial_relay', $e->getMessage(), previous: $e);
        }

        $document = @simplexml_load_string($xml);
        if (false === $document || 'Envelope' !== $document->getName()) {
            throw new CarrierException('mondial_relay', \sprintf('Unexpected answer to %s: not a SOAP envelope.', $method));
        }
        $result = $document->children('http://schemas.xmlsoap.org/soap/envelope/')->Body->children(self::NS)->{$method.'Response'}->{$method.'Result'};
        if (null === $result || !$result->count()) {
            throw new CarrierException('mondial_relay', \sprintf('Unexpected answer to %s.', $method));
        }
        $stat = (string) $result->STAT;
        if (!\in_array($stat, $accept, true)) {
            throw new CarrierException('mondial_relay', self::ERRORS[$stat] ?? 'STAT '.$stat, $stat);
        }

        return $result;
    }

    /** A document the services link to (a label's PDF). */
    public function download(string $url): string
    {
        try {
            return $this->http->request('GET', $url)->getContent();
        } catch (ExceptionInterface $e) {
            throw new CarrierException('mondial_relay', $e->getMessage(), previous: $e);
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
}
