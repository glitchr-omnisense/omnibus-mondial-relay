<?php

namespace Omnibus\MondialRelay\Tests;

/**
 * Mondial Relay's answers as its documentation gives them, for the tests: API1's SOAP envelopes (Web
 * Service V-5.14, the WSDL of Web_Services.asmx) and API2's ShipmentCreationResponse (the example of Web
 * Service Dual Carrier V-2.7.1, its encoding="utf-16" declaration over UTF-8 bytes included). No answer
 * was recorded from a live call: there is no account.
 */
final class Recorded
{
    /** API2's test credentials in these tests: made up, as the specification's own ("xxxxx"). */
    public const API2 = ['login' => 'BDTEST@business-api.mondialrelay.com', 'password' => 'test-password-not-real', 'customer_id' => 'BDTEST13'];

    public static function soap(string $method, string $result): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .\sprintf('<%1$sResponse xmlns="http://www.mondialrelay.fr/webservice/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>', $method, $result)
            .'</soap:Body></soap:Envelope>';
    }

    /** The Dual Carrier specification's response example, trimmed of most of its LabelValues. */
    public static function shipment(string $number = '96408887', ?string $output = null, string $statusList = '<StatusList />'): string
    {
        $output ??= 'http://connect.mondialrelay.com//BDTEST/etiquette/GetStickersExpeditionsAnonyme?ens=BDTEST&amp;expedition='.$number.'&amp;lg=fr-FR&amp;format=10x15&amp;crc=XXXXXXXX';

        return '<?xml version="1.0" encoding="utf-16"?>'
            .'<ShipmentCreationResponse xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.example.org/Response">'
            .'<Context><Login>BDTEST@business-api.mondialrelay.com</Login><Password>test-password-not-real</Password><CustomerId>BDTEST13</CustomerId><Culture>fr-FR</Culture><VersionAPI>1.0</VersionAPI></Context>'
            .'<OutputOptions><OutputFormat>10x15</OutputFormat><OutputType>PdfUrl</OutputType></OutputOptions>'
            .'<ShipmentsList>'.('' !== $number ? '<Shipment ShipmentNumber="'.$number.'"><LabelList><Label><RawContent>'
            .'<Sender ZoneTitle="Expediteur"><AddressLines Order="1" AddressLine="Mondial Relay" /></Sender>'
            .'<LabelValues Key="MR.Expedition.NumeroExpedition" Value="'.$number.'" />'
            .'<LabelValues Key="MR.Expedition.ReferenceExterne" Value="CMD-1042" />'
            .'<LabelValues Key="ModeSandbox" Value="False" />'
            .'<Barcodes><Barcode Type="Code128" DisplayedValue="11964088870301006623669740" Value="11964088870301006623669740" CarrierCode="MR" /></Barcodes>'
            .'</RawContent><Output>'.$output.'</Output></Label></LabelList></Shipment>' : '')
            .'</ShipmentsList>'.$statusList
            .'</ShipmentCreationResponse>';
    }
}
