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
 * The conformance suite on the transition's configuration: API1's credentials alone with legacy_labels, the
 * labels through the deprecated SOAP WSI2_CreationEtiquette and their reprint through WSI3_GetEtiquettes, as
 * Web Service V-5.14 still documents them for the accounts opened before 2024-12-23.
 */
final class MondialRelayLegacyContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new MondialRelayGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        // Mondial Relay's public test brand, published for everyone: test identifiers, not a secret.
        return ['enseigne' => 'BDTEST13', 'private_key' => 'TestAPI1key', 'legacy_labels' => true];
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
        $soap = Recorded::soap(...);

        return [
            'body:WSI4_PointRelais_Recherche' => $soap('WSI4_PointRelais_Recherche', '<STAT>0</STAT><PointsRelais><PointRelais_Details><STAT>0</STAT><Num>066974</Num><LgAdr1>RELAIS</LgAdr1><CP>75009</CP><Ville>PARIS</Ville><Pays>FR</Pays><Latitude>48,876600</Latitude><Longitude>2,325400</Longitude><Distance>350</Distance></PointRelais_Details><PointRelais_Details><STAT>0</STAT><Num>066975</Num><LgAdr1>RELAIS 2</LgAdr1><CP>75009</CP><Ville>PARIS</Ville><Pays>FR</Pays><Latitude>48,876700</Latitude><Longitude>2,325500</Longitude><Distance>700</Distance></PointRelais_Details></PointsRelais>'),
            'body:WSI2_CreationEtiquette' => $soap('WSI2_CreationEtiquette', '<STAT>0</STAT><ExpeditionNum>31231652</ExpeditionNum><URL_Etiquette>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652</URL_Etiquette>'),
            'body:WSI2_TracingColisDetaille' => $soap('WSI2_TracingColisDetaille', '<STAT>82</STAT><Tracing><ret_WSI2_sub_TracingColisDetaille><Libelle>COLIS LIVRE</Libelle><Date>03/10/2026</Date><Heure>09:12</Heure><Emplacement>PARIS</Emplacement></ret_WSI2_sub_TracingColisDetaille></Tracing>'),
            'body:WSI3_GetEtiquettes' => $soap('WSI3_GetEtiquettes', '<STAT>0</STAT><URL_PDF_A4>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652&amp;format=A4</URL_PDF_A4><URL_PDF_10x15>/ww2/PDF/StickerMaker2.aspx?ens=BDTEST1311&amp;expedition=31231652&amp;format=10x15</URL_PDF_10x15>'),
        ];
    }
}
