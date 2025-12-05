<?php

namespace josemmo\Verifactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use josemmo\Verifactu\Exceptions\AeatException;
use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\CancellationRecord;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use josemmo\Verifactu\Models\Records\RegistrationRecord;
use josemmo\Verifactu\Models\Responses\AeatResponse;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use UXML\UXML;

final class CustomAeatClient extends AeatClient
{

    public function getTaxpayer(): FiscalIdentifier
    {
        return $this->taxpayer;
    }

    public function getSystem(): ComputerSystem
    {
        return $this->system;
    }

    public function getRepresentative(): FiscalIdentifier
    {
        return $this->representative;
    }

    /**
     * Envía respuesta a un requerimiento con registros de facturación.
     * @param string $idRequerimiento ID del requerimiento (de consulta).
     * @param (RegistrationRecord|CancellationRecord)[] $records Invoicing records
     * @param bool $finRequerimiento si se envia en lotes y es el ultimo lote o no
     * @return PromiseInterface<AeatResponse> Response from service
     *
     * @throws AeatException   if AEAT server returned an error
     * @throws GuzzleException if request sending failed
     */
    public function sendRequerimiento(string $idRequerimiento, $records, $finRequerimiento): PromiseInterface
    {

        /** @phpstan-ignore generics.notGeneric */
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

        $remisionElement = $cabeceraElement->add('sum1:RemisionRequerimiento');
        $remisionElement->add('sum1:RefRequerimiento', $idRequerimiento);
        $remisionElement->add('sum1:FinRequerimiento', ($finRequerimiento ? 'S' : 'N'));

        // Add registration records
        foreach ($records as $record) {
            $record->export($baseElement->add('sum:RegistroFactura'), $this->system);
        }

        // Send request
        $options = [
            'base_uri' => $this->getBaseUri(),
            'headers' => [
                'Content-Type' => 'text/xml',
                'User-Agent' => "Mozilla/5.0 (compatible; {$this->system->name}/{$this->system->version})",
            ],
            'body' => $xml->asXML(),
        ];
        if ($this->certificatePath !== null) {
            $options['cert'] = ($this->certificatePassword === null) ?
                $this->certificatePath :
                [$this->certificatePath, $this->certificatePassword];
        }

        $responsePromise = $this->client->postAsync('/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP', $options);
        // Parse and return response
        return $responsePromise
            ->then(fn(ResponseInterface $response): string => $response->getBody()->getContents())
            ->then(fn(string $response): UXML => UXML::fromString($response))
            ->then(fn(UXML $xml): AeatResponse => AeatResponse::from($xml));
    }
}
