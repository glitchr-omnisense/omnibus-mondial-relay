<?php

namespace Omnibus\MondialRelay\Tests;

use Omnibus\Exception\CarrierException;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Cancel;
use Omnibus\Testing\Fixtures;
use Omnibus\MondialRelay\Api;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MondialRelayGatewayTest extends TestCase
{
    /** @var list<array{method: string, headers: array, body: string}> */
    private array $sent = [];

    public function testRelayPointsNearAPostcodeWithTheirHours(): void
    {
        $gateway = $this->gateway(self::soap('WSI4_PointRelais_Recherche', '<STAT>0</STAT><PointsRelais><PointRelais_Details>
            <STAT>0</STAT><Num>066974</Num><LgAdr1>TABAC DE LA GARE          </LgAdr1><LgAdr2 /><LgAdr3>4 PLACE DE LA GARE</LgAdr3><LgAdr4 />
            <CP>75009</CP><Ville>PARIS</Ville><Pays>FR</Pays><Latitude>48,876600</Latitude><Longitude>2,325400</Longitude><Distance>350</Distance>
            <Horaires_Lundi><string>0900</string><string>1230</string><string>1400</string><string>1900</string></Horaires_Lundi>
            <Horaires_Dimanche><string>0000</string><string>0000</string><string>0000</string><string>0000</string></Horaires_Dimanche>
            </PointRelais_Details></PointsRelais>'));

        $points = $gateway->pickupPoints(Fixtures::customer(), 5, new Parcel(800));

        self::assertCount(1, $points);
        self::assertSame('FR-066974', $points[0]->id);
        self::assertSame('TABAC DE LA GARE', $points[0]->name);
        self::assertSame(['4 PLACE DE LA GARE'], $points[0]->address->street);
        self::assertSame(48.8766, $points[0]->latitude);
        self::assertSame(350, $points[0]->distance);
        self::assertSame([1 => [['09:00', '12:30'], ['14:00', '19:00']]], $points[0]->openingHours);

        // The call: its SOAP action, its parameters in order, signed.
        self::assertSame('"http://www.mondialrelay.fr/webservice/WSI4_PointRelais_Recherche"', $this->header('soapaction'));
        $params = $this->params('WSI4_PointRelais_Recherche');
        self::assertSame(['Enseigne', 'Pays', 'NumPointRelais', 'Ville', 'CP', 'Latitude', 'Longitude', 'Taille', 'Poids', 'Action', 'DelaiEnvoi', 'RayonRecherche', 'TypeActivite', 'NACE', 'NombreResultats', 'Security'], array_keys($params));
        // BDTEST13 / PrivateK: Mondial Relay's public test brand (Api::TEST_ENSEIGNE, Api::TEST_PRIVATE_KEY), published for everyone - test identifiers, not a secret.
        self::assertSame(['BDTEST13', 'FR', '', 'PARIS', '75009', '', '', '', '800', '24R', '0', '', '', '', '5'], array_slice(array_values($params), 0, 15));
        self::assertSame(strtoupper(md5(implode('', array_slice(array_values($params), 0, 15)).'PrivateK')), $params['Security']);
    }

    public function testShippingToARelayBooksItAndLinksTheLabel(): void
    {
        $gateway = $this->gateway(self::soap('WSI2_CreationEtiquette', '<STAT>0</STAT><ExpeditionNum>31231652</ExpeditionNum><URL_Etiquette>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652</URL_Etiquette>'));
        $shipment = Fixtures::shipment(800);
        $shipment = new \Omnibus\Model\Shipment($shipment->sender, $shipment->recipient, $shipment->parcels, pickupPoint: 'FR-066974', reference: 'CMD-1042');

        $label = $gateway->ship($shipment);

        self::assertSame('31231652', $label->trackingNumber);
        self::assertSame('https://www.mondialrelay.com/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&expedition=31231652', $label->url);
        self::assertStringContainsString('numeroExpedition=31231652', (string) $label->trackingUrl);
        $params = $this->params('WSI2_CreationEtiquette');
        self::assertSame('24R', $params['ModeLiv']);
        self::assertSame(['FR', '066974'], [$params['LIV_Rel_Pays'], $params['LIV_Rel']]);
        // Capitals, no accents, cut to length; the phone as digits.
        self::assertSame('EMILE ZOLA', $params['Dest_Ad1']);
        self::assertSame('21 BIS RUE DE BRUXELLES', $params['Dest_Ad3']);
        self::assertSame('BATIMENT B', $params['Dest_Ad4']);
        self::assertSame('0612345678', $params['Dest_Tel1']);
        self::assertSame('+33102030405', $params['Expe_Tel1']);
        self::assertSame('800', $params['Poids']);
        self::assertSame('Security', array_key_last($params));
    }

    public function testTrackingFoldsTheStepsOntoTheCommonScale(): void
    {
        $gateway = $this->gateway(self::soap('WSI2_TracingColisDetaille', '<STAT>81</STAT><Tracing>
            <ret_WSI2_sub_TracingColisDetaille><Libelle>COLIS DISPONIBLE AU POINT RELAIS</Libelle><Date>03/10/26</Date><Heure>09:12</Heure><Emplacement>PARIS</Emplacement></ret_WSI2_sub_TracingColisDetaille>
            <ret_WSI2_sub_TracingColisDetaille><Libelle>PRISE EN CHARGE EN AGENCE</Libelle><Date>01/10/26</Date><Heure>18:40</Heure><Emplacement>LILLE</Emplacement></ret_WSI2_sub_TracingColisDetaille>
            <ret_WSI2_sub_TracingColisDetaille><Libelle /><Date /><Heure /></ret_WSI2_sub_TracingColisDetaille>
            </Tracing>'));

        $tracking = $gateway->track('31231652');

        self::assertSame(TrackingStatus::AVAILABLE_FOR_PICKUP, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('PRISE EN CHARGE EN AGENCE', $tracking->events[0]->description);
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->events[0]->status);
        self::assertSame('2026-10-03 09:12', $tracking->latest()->at->format('Y-m-d H:i'));
        self::assertSame('Europe/Paris', $tracking->latest()->at->getTimezone()->getName());
    }

    public function testACarrierErrorCarriesItsCodeAndSentence(): void
    {
        $gateway = $this->gateway(self::soap('WSI2_TracingColisDetaille', '<STAT>24</STAT>'));

        try {
            $gateway->track('nope');
            self::fail('No exception.');
        } catch (CarrierException $e) {
            self::assertSame('24', $e->carrierCode);
            self::assertSame("[mondial_relay] Numéro d'expédition ou de suivi invalide", $e->getMessage());
        }
    }

    public function testNoCancellationAndCredentialsRequired(): void
    {
        self::assertFalse($this->gateway()->supports(Cancel::class));

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "mondial_relay" gateway needs: enseigne, private_key.');
        (new MondialRelayGatewayFactory(new MockHttpClient()))->create();
    }

    public function testAddressesAreWrittenTheWayTheServicesTakeThem(): void
    {
        self::assertSame("L'ETE A ANGOULEME", Api::text("L'été à Angoulême"));
        self::assertSame('ABCDE', Api::text('abcdefgh', 5));
        self::assertSame('0612345678', Api::phone('06.12.34.56.78'));
    }

    private function gateway(string ...$responses): \Omnibus\GatewayInterface
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue) {
            $this->sent[] = ['method' => $method, 'headers' => $options['normalized_headers'], 'body' => $options['body']];

            return new MockResponse(array_shift($queue) ?? '');
        });

        return (new MondialRelayGatewayFactory($http))->create(['sandbox' => true]);
    }

    private static function soap(string $method, string $result): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .\sprintf('<%1$sResponse xmlns="http://www.mondialrelay.fr/webservice/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>', $method, $result)
            .'</soap:Body></soap:Envelope>';
    }

    private function header(string $name): string
    {
        return substr($this->sent[0]['headers'][$name][0], \strlen($name) + 2);
    }

    /** @return array<string, string> the parameters sent, in their order */
    private function params(string $method): array
    {
        $xml = simplexml_load_string($this->sent[0]['body']);
        $call = $xml->children('http://schemas.xmlsoap.org/soap/envelope/')->Body->children('http://www.mondialrelay.fr/webservice/')->{$method};
        $params = [];
        foreach ($call->children('http://www.mondialrelay.fr/webservice/') as $name => $value) {
            $params[$name] = (string) $value;
        }

        return $params;
    }
}
