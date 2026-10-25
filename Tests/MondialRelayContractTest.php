<?php

namespace Omnibus\MondialRelay\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Shipment;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\Testing\Fixtures;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Mondial Relay's recorded answers (the SOAP web service, the public test brand). */
final class MondialRelayContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new MondialRelayGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        // Mondial Relay's public test brand, published for everyone: test identifiers, not a secret.
        return ['enseigne' => 'BDTEST13', 'private_key' => 'PrivateK'];
    }

    protected function contractShipment(): Shipment
    {
        $s = Fixtures::shipment(800);

        return new Shipment($s->sender, $s->recipient, $s->parcels, pickupPoint: 'FR-066974', reference: 'CMD-1042');
    }

    protected function contractTrackingNumber(): string
    {
        return '31231652';
    }

    protected function recordedAnswers(): array
    {
        $soap = static fn (string $method, string $result) => '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .\sprintf('<%1$sResponse xmlns="http://www.mondialrelay.fr/webservice/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>', $method, $result).'</soap:Body></soap:Envelope>';
        $point = static fn (string $num, int $distance) => '<PointRelais_Details><STAT>0</STAT><Num>'.$num.'</Num><LgAdr1>RELAIS '.$num.'</LgAdr1><LgAdr2 /><LgAdr3>4 PLACE DE LA GARE</LgAdr3><LgAdr4 /><CP>75009</CP><Ville>PARIS</Ville><Pays>FR</Pays><Latitude>48,876600</Latitude><Longitude>2,325400</Longitude><Distance>'.$distance.'</Distance>'
            .'<Horaires_Lundi><string>0900</string><string>1230</string><string>1400</string><string>1900</string></Horaires_Lundi><Horaires_Dimanche><string>0000</string><string>0000</string><string>0000</string><string>0000</string></Horaires_Dimanche></PointRelais_Details>';

        return [
            'body:WSI4_PointRelais_Recherche' => $soap('WSI4_PointRelais_Recherche', '<STAT>0</STAT><PointsRelais>'.$point('066974', 350).$point('066975', 700).$point('066976', 1200).'</PointsRelais>'),
            'body:WSI2_CreationEtiquette' => $soap('WSI2_CreationEtiquette', '<STAT>0</STAT><ExpeditionNum>31231652</ExpeditionNum><URL_Etiquette>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652</URL_Etiquette>'),
            'body:WSI2_TracingColisDetaille' => $soap('WSI2_TracingColisDetaille', '<STAT>81</STAT><Tracing><ret_WSI2_sub_TracingColisDetaille><Libelle>COLIS DISPONIBLE AU POINT RELAIS</Libelle><Date>03/10/26</Date><Heure>09:12</Heure><Emplacement>PARIS</Emplacement></ret_WSI2_sub_TracingColisDetaille><ret_WSI2_sub_TracingColisDetaille><Libelle>PRISE EN CHARGE EN AGENCE</Libelle><Date>01/10/26</Date><Heure>18:40</Heure><Emplacement>LILLE</Emplacement></ret_WSI2_sub_TracingColisDetaille></Tracing>'),
            'body:WSI3_GetEtiquettes' => $soap('WSI3_GetEtiquettes', '<STAT>0</STAT><URL_PDF_A4>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652&amp;format=A4</URL_PDF_A4><URL_PDF_10x15>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652&amp;format=10x15</URL_PDF_10x15>'),
        ];
    }
}
