<?php

namespace Omnibus\MondialRelay\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Shipment;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\Testing\Fixtures;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The conformance suite on Mondial Relay's recorded answers: API1's SOAP web service (the public test brand)
 * for relay points and tracking, API2's shipment creation for labels. With API2 configured, the SOAP reprint
 * (WSI3_GetEtiquettes) is not offered: the suite checks that slip() is refused before any request.
 */
final class MondialRelayContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new MondialRelayGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        // Mondial Relay's public test brand, published for everyone: test identifiers, not a secret; API2's made up.
        return ['enseigne' => 'BDTEST13', 'private_key' => 'TestAPI1key'] + Recorded::API2;
    }

    protected function contractShipment(): Shipment
    {
        $s = Fixtures::shipment(800);

        return new Shipment($s->sender, $s->recipient, $s->parcels, pickupPoint: 'FR-066974', reference: 'CMD-1042');
    }

    protected function contractTrackingNumber(): string
    {
        return '96408887';
    }

    protected function recordedAnswers(): array
    {
        $soap = Recorded::soap(...);
        $point = static fn (string $num, int $distance) => '<PointRelais_Details><STAT>0</STAT><Num>'.$num.'</Num><LgAdr1>RELAIS '.$num.'</LgAdr1><LgAdr2 /><LgAdr3>4 PLACE DE LA GARE</LgAdr3><LgAdr4 /><CP>75009</CP><Ville>PARIS</Ville><Pays>FR</Pays><Latitude>48,876600</Latitude><Longitude>2,325400</Longitude><Distance>'.$distance.'</Distance>'
            .'<Horaires_Lundi><string>0900</string><string>1230</string><string>1400</string><string>1900</string></Horaires_Lundi><Horaires_Dimanche><string>0000</string><string>0000</string><string>0000</string><string>0000</string></Horaires_Dimanche></PointRelais_Details>';

        return [
            'body:WSI4_PointRelais_Recherche' => $soap('WSI4_PointRelais_Recherche', '<STAT>0</STAT><PointsRelais>'.$point('066974', 350).$point('066975', 700).$point('066976', 1200).'</PointsRelais>'),
            'POST body:<ShipmentCreationRequest' => Recorded::shipment(),
            'body:WSI2_TracingColisDetaille' => $soap('WSI2_TracingColisDetaille', '<STAT>81</STAT><Tracing><ret_WSI2_sub_TracingColisDetaille><Libelle>COLIS DISPONIBLE AU POINT RELAIS</Libelle><Date>03/10/26</Date><Heure>09:12</Heure><Emplacement>PARIS</Emplacement></ret_WSI2_sub_TracingColisDetaille><ret_WSI2_sub_TracingColisDetaille><Libelle>PRISE EN CHARGE EN AGENCE</Libelle><Date>01/10/26</Date><Heure>18:40</Heure><Emplacement>LILLE</Emplacement></ret_WSI2_sub_TracingColisDetaille></Tracing>'),
        ];
    }
}
