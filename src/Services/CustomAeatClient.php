<?php

namespace josemmo\Verifactu\Services;

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;
use Exception;

class CustomAeatClient extends AeatClient
{
    /**
     * Obtiene los objetos ComputerSystem usados por el cliente
     *
     * @return ComputerSystem
     */
    public function getSystem(): ComputerSystem
    {
        return $this->system;
    }
    /**
     * Obtiene los objetos  FiscalIdentifier usados por el cliente
     *
     * @return FiscalIdentifier
     */
    public function getTaxpayer(): FiscalIdentifier
    {
        return $this->taxpayer;
    }

    /**
     * Firma un XML usando XAdES Enveloped (SHA-256)
     *
     * @param string $xmlContent XML canonicalizado
     * @return string XML firmado con ds:Signature
     * @throws Exception
     */
    public function signXAdES(string $xmlContent): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($xmlContent);

        // Crear objeto de firma
        $objDSig = new XMLSecurityDSig();
        $objDSig->setCanonicalMethod(XMLSecurityDSig::C14N);

        // Referencia al XML completo
        $objDSig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature']
        );

        // Cargar clave privada
        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type'=>'private']);
        $objKey->loadKey($this->certificatePath, true, true, $this->certificatePassword);

        // Firmar XML
        $objDSig->sign($objKey);

        // Adjuntar firma al elemento raíz
        $objDSig->appendSignature($dom->documentElement);

        // Devolver XML canonicalizado firmado
        return $dom->C14N(false, false);
    }
}
