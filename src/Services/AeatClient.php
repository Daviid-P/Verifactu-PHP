<?php

namespace josemmo\Verifactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use UXML\UXML;
use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\CancellationRecord;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use josemmo\Verifactu\Models\Records\ForeignFiscalIdentifier;
use josemmo\Verifactu\Models\Records\RegistrationRecord;

/**
 * Class to communicate with the AEAT web service endpoint for VERI*FACTU
 */
class AeatClient
{
    public const NS_SOAPENV = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_SUM = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
    public const NS_SUM1 = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
    const NS_CON = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/ConsultaLR.xsd';

    private readonly ComputerSystem $system;
    private readonly FiscalIdentifier $taxpayer;
    private ?FiscalIdentifier $representative = null;
    private readonly Client $client;
    private bool $isProduction = true;

    /**
     * Class constructor
     *
     * NOTE: The certificate path must have the ".p12" extension to be recognized as a PFX bundle.
     *
     * @param ComputerSystem   $system       Computer system details
     * @param FiscalIdentifier $taxpayer     Taxpayer details (party that issues the invoices)
     * @param string           $certPath     Path to encrypted PEM certificate or PKCS#12 (PFX) bundle
     * @param string|null      $certPassword Certificate password or `null` for none
     */
    public function __construct(
        ComputerSystem $system,
        FiscalIdentifier $taxpayer,
        string $certPath,
        ?string $certPassword = null,
    ) {
        $this->system = $system;
        $this->taxpayer = $taxpayer;
        $this->client = new Client([
            'cert' => ($certPassword === null) ? $certPath : [$certPath, $certPassword],
            'headers' => [
                'User-Agent' => "Mozilla/5.0 (compatible; {$system->name}/{$system->version})",
            ],
        ]);
    }

    /**
     * Set representative
     *
     * NOTE: Requires the represented fiscal entity to fill the "GENERALLEY58" form at AEAT.
     *
     * @param FiscalIdentifier|null $representative Representative details (party that sends the invoices)
     *
     * @return $this This instance
     */
    public function setRepresentative(?FiscalIdentifier $representative): static
    {
        $this->representative = $representative;
        return $this;
    }

    /**
     * Set production environment
     *
     * @param bool $production Pass `true` for production, `false` for testing
     *
     * @return $this This instance
     */
    public function setProduction(bool $production): static
    {
        $this->isProduction = $production;
        return $this;
    }

    /**
     * Send registration records
     *
     * @param  RegistrationRecord[] $records Registration records
     *
     * @return UXML XML response from web service
     *
     * @throws GuzzleException if request failed
     *
     * @deprecated 0.0.3 Use the `send()` method instead.
     * @see AeatClient::send
     */
    public function sendRegistrationRecords(array $records): UXML
    {
        return $this->send($records);
    }

    /**
     * Send invoicing records
     *
     * @param (RegistrationRecord|CancellationRecord)[] $records Invoicing records
     *
     * @return UXML                          XML response from web service
     *
     * @throws GuzzleException if request failed
     */
    public function send(array $records): UXML
    {
        // Build initial request
        $xml = UXML::newInstance('soapenv:Envelope', null, [
            'xmlns:soapenv' => self::NS_SOAPENV,
            'xmlns:sum' => self::NS_SUM,
            'xmlns:sum1' => self::NS_SUM1,
        ]);
        $xml->add('soapenv:Header');
        $baseElement = $xml->add('soapenv:Body')->add('sum:RegFactuSistemaFacturacion');

        // Add header
        $cabeceraElement = $baseElement->add('sum:Cabecera');
        $obligadoEmisionElement = $cabeceraElement->add('sum1:ObligadoEmision');
        $obligadoEmisionElement->add('sum1:NombreRazon', $this->taxpayer->name);
        $obligadoEmisionElement->add('sum1:NIF', $this->taxpayer->nif);
        if ($this->representative !== null) {
            $representanteElement = $cabeceraElement->add('sum1:Representante');
            $representanteElement->add('sum1:NombreRazon', $this->representative->name);
            $representanteElement->add('sum1:NIF', $this->representative->nif);
        }

        // Add registration records
        foreach ($records as $record) {
            $isRegistrationRecord = $record instanceof RegistrationRecord;
            $recordElementName = $isRegistrationRecord ? 'RegistroAlta' : 'RegistroAnulacion';
            $recordElement = $baseElement->add('sum:RegistroFactura')->add("sum1:$recordElementName");
            $recordElement->add('sum1:IDVersion', '1.0');

            if ($isRegistrationRecord) {
                $this->addRegistrationRecordProperties($recordElement, $record);
            } else {
                $this->addCancellationRecordProperties($recordElement, $record);
            }

            $encadenamientoElement = $recordElement->add('sum1:Encadenamiento');
            if ($record->previousInvoiceId === null) {
                $encadenamientoElement->add('sum1:PrimerRegistro', 'S');
            } else {
                $registroAnteriorElement = $encadenamientoElement->add('sum1:RegistroAnterior');
                $registroAnteriorElement->add('sum1:IDEmisorFactura', $record->previousInvoiceId->issuerId);
                $registroAnteriorElement->add('sum1:NumSerieFactura', $record->previousInvoiceId->invoiceNumber);
                $registroAnteriorElement->add('sum1:FechaExpedicionFactura', $record->previousInvoiceId->issueDate->format('d-m-Y'));
                $registroAnteriorElement->add('sum1:Huella', $record->previousHash);
            }

            $sistemaInformaticoElement = $recordElement->add('sum1:SistemaInformatico');
            $sistemaInformaticoElement->add('sum1:NombreRazon', $this->system->vendorName);
            $sistemaInformaticoElement->add('sum1:NIF', $this->system->vendorNif);
            $sistemaInformaticoElement->add('sum1:NombreSistemaInformatico', $this->system->name);
            $sistemaInformaticoElement->add('sum1:IdSistemaInformatico', $this->system->id);
            $sistemaInformaticoElement->add('sum1:Version', $this->system->version);
            $sistemaInformaticoElement->add('sum1:NumeroInstalacion', $this->system->installationNumber);
            $sistemaInformaticoElement->add('sum1:TipoUsoPosibleSoloVerifactu', $this->system->onlySupportsVerifactu ? 'S' : 'N');
            $sistemaInformaticoElement->add('sum1:TipoUsoPosibleMultiOT', $this->system->supportsMultipleTaxpayers ? 'S' : 'N');
            $sistemaInformaticoElement->add('sum1:IndicadorMultiplesOT', $this->system->hasMultipleTaxpayers ? 'S' : 'N');

            $recordElement->add('sum1:FechaHoraHusoGenRegistro', $record->hashedAt->format('c'));
            $recordElement->add('sum1:TipoHuella', '01'); // SHA-256
            $recordElement->add('sum1:Huella', $record->hash);
        }

        // Send request
        $response = $this->client->post('/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', [
            'base_uri' => $this->getBaseUri(),
            'headers' => [
                'Content-Type' => 'text/xml',
            ],
            'body' => $xml->asXML(),
        ]);
        return $this->handleResponse($response);
    }

    /**
     * Add registration record properties
     *
     * @param UXML               $recordElement Element to fill
     * @param RegistrationRecord $record        Registration record instance
     */
    private function addRegistrationRecordProperties(UXML $recordElement, RegistrationRecord $record): void
    {
        $idFacturaElement = $recordElement->add('sum1:IDFactura');
        $idFacturaElement->add('sum1:IDEmisorFactura', $record->invoiceId->issuerId);
        $idFacturaElement->add('sum1:NumSerieFactura', $record->invoiceId->invoiceNumber);
        $idFacturaElement->add('sum1:FechaExpedicionFactura', $record->invoiceId->issueDate->format('d-m-Y'));

        $recordElement->add('sum1:NombreRazonEmisor', $record->issuerName);
        $recordElement->add('sum1:TipoFactura', $record->invoiceType->value);

        if ($record->correctiveType !== null) {
            $recordElement->add('sum1:TipoRectificativa', $record->correctiveType->value);
        }
        if (count($record->correctedInvoices) > 0) {
            $facturasRectificadasElement = $recordElement->add('sum1:FacturasRectificadas');
            foreach ($record->correctedInvoices as $correctedInvoice) {
                $facturaRectificadaElement = $facturasRectificadasElement->add('sum1:IDFacturaRectificada');
                $facturaRectificadaElement->add('sum1:IDEmisorFactura', $correctedInvoice->issuerId);
                $facturaRectificadaElement->add('sum1:NumSerieFactura', $correctedInvoice->invoiceNumber);
                $facturaRectificadaElement->add('sum1:FechaExpedicionFactura', $correctedInvoice->issueDate->format('d-m-Y'));
            }
        }
        if (count($record->replacedInvoices) > 0) {
            $facturasSustituidasElement = $recordElement->add('sum1:FacturasSustituidas');
            foreach ($record->replacedInvoices as $replacedInvoice) {
                $facturaSustituidaElement = $facturasSustituidasElement->add('sum1:IDFacturaSustituida');
                $facturaSustituidaElement->add('sum1:IDEmisorFactura', $replacedInvoice->issuerId);
                $facturaSustituidaElement->add('sum1:NumSerieFactura', $replacedInvoice->invoiceNumber);
                $facturaSustituidaElement->add('sum1:FechaExpedicionFactura', $replacedInvoice->issueDate->format('d-m-Y'));
            }
        }
        if ($record->correctedBaseAmount !== null && $record->correctedTaxAmount !== null) {
            $importeRectificacionElement = $recordElement->add('sum1:ImporteRectificacion');
            $importeRectificacionElement->add('sum1:BaseRectificada', $record->correctedBaseAmount);
            $importeRectificacionElement->add('sum1:CuotaRectificada', $record->correctedTaxAmount);
        }

        $recordElement->add('sum1:DescripcionOperacion', $record->description);

        if (count($record->recipients) > 0) {
            $destinatariosElement = $recordElement->add('sum1:Destinatarios');
            foreach ($record->recipients as $recipient) {
                $destinatarioElement = $destinatariosElement->add('sum1:IDDestinatario');
                $destinatarioElement->add('sum1:NombreRazon', $recipient->name);
                if ($recipient instanceof FiscalIdentifier) {
                    $destinatarioElement->add('sum1:NIF', $recipient->nif);
                } else {
                    $idOtroElement = $destinatarioElement->add('sum1:IDOtro');
                    $idOtroElement->add('sum1:CodigoPais', $recipient->country);
                    $idOtroElement->add('sum1:IDType', $recipient->type->value);
                    $idOtroElement->add('sum1:ID', $recipient->value);
                }
            }
        }

        $desgloseElement = $recordElement->add('sum1:Desglose');
        foreach ($record->breakdown as $breakdownDetails) {
            $detalleDesgloseElement = $desgloseElement->add('sum1:DetalleDesglose');
            $detalleDesgloseElement->add('sum1:Impuesto', $breakdownDetails->taxType->value);
            $detalleDesgloseElement->add('sum1:ClaveRegimen', $breakdownDetails->regimeType->value);
            $detalleDesgloseElement->add('sum1:CalificacionOperacion', $breakdownDetails->operationType->value);
            $detalleDesgloseElement->add('sum1:TipoImpositivo', $breakdownDetails->taxRate);
            $detalleDesgloseElement->add('sum1:BaseImponibleOimporteNoSujeto', $breakdownDetails->baseAmount);
            $detalleDesgloseElement->add('sum1:CuotaRepercutida', $breakdownDetails->taxAmount);
        }

        $recordElement->add('sum1:CuotaTotal', $record->totalTaxAmount);
        $recordElement->add('sum1:ImporteTotal', $record->totalAmount);
    }

    /**
     * Add cancellation record properties
     *
     * @param UXML               $recordElement Element to fill
     * @param CancellationRecord $record        Cancellation record instance
     */
    private function addCancellationRecordProperties(UXML $recordElement, CancellationRecord $record): void
    {
        $idFacturaElement = $recordElement->add('sum1:IDFactura');
        $idFacturaElement->add('sum1:IDEmisorFacturaAnulada', $record->invoiceId->issuerId);
        $idFacturaElement->add('sum1:NumSerieFacturaAnulada', $record->invoiceId->invoiceNumber);
        $idFacturaElement->add('sum1:FechaExpedicionFacturaAnulada', $record->invoiceId->issueDate->format('d-m-Y'));
    }

    /**
     * Get base URI of web service
     *
     * @return string Base URI
     */
    private function getBaseUri(): string {
        return $this->isProduction ? 'https://www1.agenciatributaria.gob.es' : 'https://prewww1.aeat.es';
    }

    /**
     * Consult registration records
     *
     * @param array $request Array with 'Cabecera', 'FiltroConsulta', 'DatosAdicionalesRespuesta'
     * @return UXML XML response from AEAT web service
     * @throws GuzzleException if request fails
     */
    public function consultaFactuSistemaFacturacion(array $request): UXML
    {
        // Build request XML
        $xml = UXML::newInstance('soapenv:Envelope', null, [
            'xmlns:soapenv' => self::NS_SOAPENV,
            'xmlns:con'     => self::NS_CON,
            'xmlns:sum1'     => self::NS_SUM1,
        ]);
        $xml->add('soapenv:Header');
        $body = $xml->add('soapenv:Body');

        // Root element
        $root = $body->add('con:ConsultaFactuSistemaFacturacion');

        // Cabecera
        $cabecera = $root->add('con:Cabecera');
        $cabecera->add('sum1:IDVersion', $request['Cabecera']['IDVersion'] ?? '1.0');

        if (!empty($request['Cabecera']['ObligadoEmision'])) {
            $obligado = $cabecera->add('sum1:ObligadoEmision');
            $obligado->add('sum1:NombreRazon', $request['Cabecera']['ObligadoEmision']['NombreRazon'] ?? '');
            $obligado->add('sum1:NIF', $request['Cabecera']['ObligadoEmision']['NIF'] ?? '');
        }

        // FiltroConsulta
        $filtro = $root->add('con:FiltroConsulta');

        if (!empty($request['FiltroConsulta']['PeriodoImputacion'])) {
            $periodo = $filtro->add('con:PeriodoImputacion');
            $periodo->add('sum1:Ejercicio', $request['FiltroConsulta']['PeriodoImputacion']['Ejercicio']);
            $periodo->add('sum1:Periodo', $request['FiltroConsulta']['PeriodoImputacion']['Periodo']);
        }

        // $sistemaInformaticoElement = $filtro->add('con:SistemaInformatico');
        // $sistemaInformaticoElement->add('con:NombreRazon', $this->system->vendorName);
        // $sistemaInformaticoElement->add('con:NIF', $this->system->vendorNif);
        // $sistemaInformaticoElement->add('con:NombreSistemaInformatico', $this->system->name);
        // $sistemaInformaticoElement->add('con:IdSistemaInformatico', $this->system->id);
        // $sistemaInformaticoElement->add('con:Version', $this->system->version);
        // $sistemaInformaticoElement->add('con:NumeroInstalacion', $this->system->installationNumber);
        // $sistemaInformaticoElement->add('con:TipoUsoPosibleSoloVerifactu', $this->system->onlySupportsVerifactu ? 'S' : 'N');
        // $sistemaInformaticoElement->add('con:TipoUsoPosibleMultiOT', $this->system->supportsMultipleTaxpayers ? 'S' : 'N');
        // $sistemaInformaticoElement->add('con:IndicadorMultiplesOT', $this->system->hasMultipleTaxpayers ? 'S' : 'N');


        // Optional: ClavePaginacion
        if (!empty($request['FiltroConsulta']['ClavePaginacion'])) {
            $clave = $filtro->add('con:ClavePaginacion');
            foreach ($request['FiltroConsulta']['ClavePaginacion'] as $key => $value) {
                $clave->add("con:$key", $value);
            }
        }

        // Optional: DatosAdicionalesRespuesta
        if (!empty($request['DatosAdicionalesRespuesta'])) {
            $datos = $root->add('con:DatosAdicionalesRespuesta');
            foreach ($request['DatosAdicionalesRespuesta'] as $key => $value) {
                $datos->add("con:$key", $value);
            }
        }

        // Send request
        $response = $this->client->post('/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', [
            'base_uri' => $this->getBaseUri(),
            'headers'  => ['Content-Type' => 'text/xml'],
            'body' => $xml->asXML(),
        ]);
        return $this->handleResponse($response);
    }

    /**
     * Handles the response from the AEAT web service.
     *
     * @param  ResponseInterface $response Guzzle response object
     * @return UXML                        XML response
     * @throws \RuntimeException if a SOAP Fault is found in the response
     */
    private function handleResponse(ResponseInterface $response): UXML
    {
        $body = $response->getBody()->getContents();

        // Use DOMDocument and DOMXPath to robustly check for the SOAP Fault,
        // as UXML's namespace handling is not sufficient for this case.
        $dom = new \DOMDocument();
        @$dom->loadXML($body);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('env', self::NS_SOAPENV);
        $faultNodes = $xpath->query('//env:Fault');

        if ($faultNodes->length > 0) {
            $faultCode = $xpath->query('faultcode', $faultNodes->item(0))->item(0)->textContent;
            $faultString = $xpath->query('faultstring', $faultNodes->item(0))->item(0)->textContent;
            throw new \RuntimeException("SOAP Fault: [{$faultCode}] {$faultString}");
        }

        return UXML::fromString($body);
    }
}
