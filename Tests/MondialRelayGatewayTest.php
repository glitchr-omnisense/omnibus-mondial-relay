<?php

namespace Omnibus\MondialRelay\Tests;

use Omnibus\Exception\CarrierException;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\GatewayInterface;
use Omnibus\Model\Label;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use Omnibus\MondialRelay\Api;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\MondialRelay\ShipmentApi;
use Omnibus\Request\Cancel;
use Omnibus\Request\GetSlip;
use Omnibus\Testing\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MondialRelayGatewayTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: string}> */
    private array $sent = [];

    public function testRelayPointsNearAPostcodeWithTheirHours(): void
    {
        $gateway = $this->gateway([], Recorded::soap('WSI4_PointRelais_Recherche', '<STAT>0</STAT><PointsRelais><PointRelais_Details>
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
        self::assertSame(Api::ENDPOINT, $this->sent[0]['url']);
        self::assertSame('"http://www.mondialrelay.fr/webservice/WSI4_PointRelais_Recherche"', $this->header('soapaction'));
        $params = $this->params('WSI4_PointRelais_Recherche');
        self::assertSame(['Enseigne', 'Pays', 'NumPointRelais', 'Ville', 'CP', 'Latitude', 'Longitude', 'Taille', 'Poids', 'Action', 'DelaiEnvoi', 'RayonRecherche', 'TypeActivite', 'NACE', 'NombreResultats', 'Security'], array_keys($params));
        // BDTEST13 / TestAPI1key: Mondial Relay's public test brand (Api::TEST_ENSEIGNE, Api::TEST_PRIVATE_KEY), published for everyone - test identifiers, not a secret.
        self::assertSame(['BDTEST13', 'FR', '', 'PARIS', '75009', '', '', '', '800', '24R', '0', '', '', '', '5'], \array_slice(array_values($params), 0, 15));
        self::assertSame(strtoupper(md5(implode('', \array_slice(array_values($params), 0, 15)).'TestAPI1key')), $params['Security']);
    }

    public function testTheSignatureIsTheDocumentedOne(): void
    {
        // Web Service V-5.14's own example: "BDTEST13" + "12345678" + "FR" + the key. Its printed hash,
        // 33DA5F12..., is the one of the former test key PrivateK: the document changed the key, not the hash.
        self::assertSame('33DA5F122DAA40241087CC7845BEA4B1', Api::security(['Enseigne' => 'BDTEST13', 'Expeditions' => '12345678', 'Langue' => 'FR'], 'PrivateK'));
        self::assertSame(strtoupper(md5('BDTEST1312345678FRTestAPI1key')), Api::security(['Enseigne' => 'BDTEST13', 'Expeditions' => '12345678', 'Langue' => 'FR'], Api::TEST_PRIVATE_KEY));
    }

    public function testShippingToARelayBooksItThroughApi2(): void
    {
        $gateway = $this->gateway(Recorded::API2, Recorded::shipment());
        $base = Fixtures::shipment(800);
        $shipment = new Shipment($base->sender, $base->recipient, $base->parcels, pickupPoint: 'FR-066974', reference: 'cmd-1042', options: ['insurance' => '3', 'instructions' => 'Sonner deux fois, merci', 'content' => 'Thé vert']);

        $label = $gateway->ship($shipment);

        self::assertSame('96408887', $label->trackingNumber);
        self::assertSame(Label::PDF, $label->format);
        self::assertNull($label->content);
        self::assertSame('http://connect.mondialrelay.com//BDTEST/etiquette/GetStickersExpeditionsAnonyme?ens=BDTEST&expedition=96408887&lg=fr-FR&format=10x15&crc=XXXXXXXX', $label->url);
        self::assertStringContainsString('numeroExpedition=96408887', (string) $label->trackingUrl);

        // One POST to API2, its headers, its Context with the API2 credentials, never the private key.
        self::assertCount(1, $this->sent);
        self::assertSame(['POST', ShipmentApi::SANDBOX_ENDPOINT], [$this->sent[0]['method'], $this->sent[0]['url']]);
        self::assertSame('application/xml', $this->header('accept'));
        self::assertSame('text/xml', $this->header('content-type'));
        self::assertStringNotContainsString('TestAPI1key', $this->sent[0]['body']);
        $request = $this->request();
        self::assertSame('ShipmentCreationRequest', $request->getName());
        $context = [];
        foreach ($request->Context->children() ?? [] as $field) {
            $context[$field->getName()] = (string) $field;
        }
        self::assertSame(['Login' => 'BDTEST@business-api.mondialrelay.com', 'Password' => 'test-password-not-real', 'CustomerId' => 'BDTEST13', 'Culture' => 'fr-FR', 'VersionAPI' => '1.0'], $context);
        self::assertSame(['10x15', 'PdfUrl'], [(string) $request->OutputOptions->OutputFormat, (string) $request->OutputOptions->OutputType]);

        $s = $request->ShipmentsList->Shipment;
        self::assertSame(['24R', 'FR-066974'], [(string) $s->DeliveryMode['Mode'], (string) $s->DeliveryMode['Location']]);
        self::assertSame('CCC', (string) $s->CollectionMode['Mode']);
        self::assertSame('CMD-1042', (string) $s->OrderNo);
        self::assertSame('1', (string) $s->ParcelCount);
        self::assertSame(['800', 'gr'], [(string) $s->Parcels->Parcel->Weight['Value'], (string) $s->Parcels->Parcel->Weight['Unit']]);
        self::assertSame('THE VERT', (string) $s->Parcels->Parcel->Content);
        self::assertSame('SONNER DEUX FOIS, MERCI', (string) $s->DeliveryInstruction);
        self::assertSame(['ASS', '3'], [(string) $s->Options->Option['Key'], (string) $s->Options->Option['Value']]);
        // Capitals, no accents, cut to length; the phone as digits.
        $to = $s->Recipient->Address;
        self::assertSame('EMILE ZOLA', (string) $to->AddressAdd1);
        self::assertSame('21 BIS RUE DE BRUXELLES', (string) $to->Streetname);
        self::assertSame('BATIMENT B', (string) $to->AddressAdd3);
        self::assertSame('0612345678', (string) $to->PhoneNo);
        self::assertSame('+33102030405', (string) $s->Sender->Address->PhoneNo);
    }

    public function testAZplLabelComesInline(): void
    {
        $gateway = $this->gateway(Recorded::API2 + ['label_format' => 'zpl'], Recorded::shipment(output: base64_encode('^XA^FO50,50^FDMR^FS^XZ')));

        $label = $gateway->ship(Fixtures::shipment(800));

        self::assertSame(Label::ZPL, $label->format);
        self::assertSame('^XA^FO50,50^FDMR^FS^XZ', $label->content);
        self::assertSame(['Generic_ZPL_10x15_200dpi', 'ZplCode'], [(string) $this->request()->OutputOptions->OutputFormat, (string) $this->request()->OutputOptions->OutputType]);
        self::assertSame('HOM', (string) $this->request()->ShipmentsList->Shipment->DeliveryMode['Mode']);
    }

    public function testABookedLabelWhoseDownloadFailsKeepsItsLink(): void
    {
        $gateway = $this->gateway(Recorded::API2 + ['download_labels' => true], Recorded::shipment(), new MockResponse('', ['http_code' => 503]));

        $label = $gateway->ship(Fixtures::shipment(800));

        // The shipment stands: its number and the link to print it from later, not a failure that loses both.
        self::assertSame('96408887', $label->trackingNumber);
        self::assertNull($label->content);
        self::assertNotNull($label->url);
        self::assertCount(2, $this->sent);
    }

    public function testApi2RefusalsCarryTheirCodeAndWarningsDoNot(): void
    {
        $refused = Recorded::shipment('', statusList: '<StatusList><Status Code="10014" Level="Warning" Message="Invalid order number. Statement ignored." /><Status Code="10044" Level="Error" Message="Invalid postcode defined in the address." /></StatusList>');
        try {
            $this->gateway(Recorded::API2, $refused)->ship(Fixtures::shipment(800));
            self::fail('No exception.');
        } catch (CarrierException $e) {
            self::assertSame('10044', $e->carrierCode);
            self::assertSame('[mondial_relay] Invalid postcode defined in the address.', $e->getMessage());
            self::assertStringNotContainsString('test-password-not-real', $e->getMessage());
        }

        // A warning alone books the shipment.
        $warned = Recorded::shipment(statusList: '<StatusList><Status Code="10069" Level="Warning" Message="Postal code modified by the partner for routing purpose." /></StatusList>');
        self::assertSame('96408887', $this->gateway(Recorded::API2, $warned)->ship(Fixtures::shipment(800))->trackingNumber);
    }

    public function testALostAnswerSaysTheShipmentMayExist(): void
    {
        try {
            $this->gateway(Recorded::API2, new MockResponse('', ['error' => 'Operation timed out']))->ship(Fixtures::shipment(800));
            self::fail('No exception.');
        } catch (CarrierException $e) {
            self::assertSame('uncertain', $e->carrierCode);
            self::assertStringContainsString(ShipmentApi::UNCERTAIN, $e->getMessage());
        }
        // A refusal before booking (401) says nothing of the kind.
        try {
            $this->gateway(Recorded::API2, new MockResponse('', ['http_code' => 401]))->ship(Fixtures::shipment(800));
            self::fail('No exception.');
        } catch (CarrierException $e) {
            self::assertNull($e->carrierCode);
        }
    }

    public function testTheOldCredentialsAloneNameTheNewOptions(): void
    {
        $gateway = $this->gateway([]);

        // Relay points and tracking still work on API1; a label is a configuration error, before any call.
        try {
            $gateway->ship(Fixtures::shipment(800));
            self::fail('No exception.');
        } catch (InvalidConfigException $e) {
            foreach (['login', 'password', 'customer_id', 'legacy_labels'] as $option) {
                self::assertStringContainsString($option, $e->getMessage());
            }
        }
        self::assertSame([], $this->sent);
        // The SOAP reprint stays where the labels come from the SOAP service.
        self::assertTrue($gateway->supports(GetSlip::class));
        self::assertFalse($this->gateway(Recorded::API2)->supports(GetSlip::class));
    }

    public function testLegacyLabelsKeepTheSoapCallForTheTransition(): void
    {
        $gateway = $this->gateway(['legacy_labels' => true], Recorded::soap('WSI2_CreationEtiquette', '<STAT>0</STAT><ExpeditionNum>31231652</ExpeditionNum><URL_Etiquette>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652</URL_Etiquette>'));
        $base = Fixtures::shipment(800);

        $label = @$gateway->ship(new Shipment($base->sender, $base->recipient, $base->parcels, pickupPoint: 'FR-066974', reference: 'CMD-1042'));

        self::assertSame('31231652', $label->trackingNumber);
        self::assertSame('https://www.mondialrelay.com/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&expedition=31231652', $label->url);
        $params = $this->params('WSI2_CreationEtiquette');
        self::assertSame(['24R', 'FR', '066974'], [$params['ModeLiv'], $params['LIV_Rel_Pays'], $params['LIV_Rel']]);
        self::assertSame('EMILE ZOLA', $params['Dest_Ad1']);
        self::assertSame('Security', array_key_last($params));
    }

    public function testPartOfTheApi2CredentialsIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "mondial_relay" gateway needs: password, customer_id.');
        (new MondialRelayGatewayFactory(new MockHttpClient()))->create(['sandbox' => true, 'login' => 'someone']);
    }

    public function testTrackingFoldsTheStepsOntoTheCommonScale(): void
    {
        $gateway = $this->gateway([], Recorded::soap('WSI2_TracingColisDetaille', '<STAT>81</STAT><Tracing>
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
        $gateway = $this->gateway([], Recorded::soap('WSI2_TracingColisDetaille', '<STAT>24</STAT>'));

        try {
            $gateway->track('nope');
            self::fail('No exception.');
        } catch (CarrierException $e) {
            self::assertSame('24', $e->carrierCode);
            self::assertSame('[mondial_relay] Incorrect shipment number', $e->getMessage());
        }
    }

    public function testNoCancellationAndCredentialsRequired(): void
    {
        self::assertFalse($this->gateway([])->supports(Cancel::class));

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

    /** @param array<string, mixed> $options on top of sandbox: true */
    private function gateway(array $options, string|MockResponse ...$responses): GatewayInterface
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            /** @var array<string, list<string>> $headers */
            $headers = $options['normalized_headers'] ?? [];
            $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];
            $next = array_shift($queue) ?? '';

            return $next instanceof MockResponse ? $next : new MockResponse($next);
        });

        return (new MondialRelayGatewayFactory($http))->create(['sandbox' => true] + $options);
    }

    private function header(string $name): string
    {
        return substr($this->sent[0]['headers'][$name][0] ?? '', \strlen($name) + 2);
    }

    private function request(): \SimpleXMLElement
    {
        $xml = simplexml_load_string($this->sent[0]['body']);
        self::assertNotFalse($xml);

        return $xml;
    }

    /** @return array<string, string> the parameters sent, in their order */
    private function params(string $method): array
    {
        $ns = 'http://www.mondialrelay.fr/webservice/';
        $call = $this->request()->children('http://schemas.xmlsoap.org/soap/envelope/')->Body->children($ns)->{$method};
        $params = [];
        foreach ($call->children($ns) ?? [] as $value) {
            \assert($value instanceof \SimpleXMLElement);
            $params[$value->getName()] = (string) $value;
        }

        return $params;
    }
}
