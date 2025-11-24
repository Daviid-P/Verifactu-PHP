<?php

namespace josemmo\Verifactu\Services;

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use DOMDocument;
use Exception;
use ReflectionProperty;
use DateTime;
use DateTimeZone;


final class CustomAeatClient extends AeatClient
{
    /**
     * Obtiene los objetos ComputerSystem usados por el cliente
     *
     * @return ComputerSystem
     */
    public function getSystem(): ComputerSystem
    {
        $reflector = new ReflectionProperty(AeatClient::class, 'system');
        $reflector->setAccessible(true);
        return $reflector->getValue($this);
    }

    /**
     * Obtiene los objetos FiscalIdentifier usados por el cliente
     *
     * @return FiscalIdentifier
     */
    public function getTaxpayer(): FiscalIdentifier
    {
        $reflector = new ReflectionProperty(AeatClient::class, 'taxpayer');
        $reflector->setAccessible(true);
        return $reflector->getValue($this);
    }

    /**
     * Función para firmar un XML de Registro de Evento con XAdES-EPES.
     *
     * @param string $xmlContent XML canónico y completo del registro (e.g., RegistroEvento).
     * @return string XML firmado completo.
     * @throws Exception Si hay error en la firma o lectura del certificado.
     */
    function firmaVeriFactuXml(string $xmlContent): string
    {
        // ===================== CARGA CERTIFICADO (igual que antes) =====================
        $certPathReflector = new ReflectionProperty(AeatClient::class, 'certificatePath');
        $certPathReflector->setAccessible(true);
        $certificatePath = $certPathReflector->getValue($this);

        $certPassReflector = new ReflectionProperty(AeatClient::class, 'certificatePassword');
        $certPassReflector->setAccessible(true);
        $certificatePassword = $certPassReflector->getValue($this);

        $pfx = @file_get_contents($certificatePath);
        if ($pfx === false) throw new Exception("No se pudo leer el PFX: $certificatePath");

        $certs = [];
        if (!openssl_pkcs12_read($pfx, $certs, $certificatePassword)) {
            throw new Exception("PFX inválido o contraseña incorrecta");
        }

        $certPem = $certs['cert'];
        $privateKeyPem = $certs['pkey'];

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) throw new Exception("Clave privada inválida");

        $certInfo = openssl_x509_parse($certPem);

        // IssuerName en orden correcto (root → leaf)
        $issuerParts = [];
        foreach (array_reverse($certInfo['issuer']) as $k => $v) $issuerParts[] = "$k=$v";
        $issuerName = implode(', ', $issuerParts);

        $serialNumber = $certInfo['serialNumber'];

        // Certificado limpio para <X509Certificate> y para digest SHA1
        $certClean = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certPem);
        $certSha1 = base64_encode(hash('sha1', base64_decode($certClean), true));

        // ===================== PREPARAR DOM =====================
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xmlContent);
        $dom->encoding = 'UTF-8';

        $root = $dom->documentElement;

        // ===================== IDs =====================
        $sigId = 'Signature-' . bin2hex(random_bytes(10));
        $signedPropsId = $sigId . '-SignedProperties';

        // ===================== NAMESPACES =====================
        $dsNS    = 'http://www.w3.org/2000/09/xmldsig#';
        $xadesNS = 'http://uri.etsi.org/01903/v1.3.2#';

        // ===================== CREAR <ds:Signature> =====================
        $signature = $dom->createElementNS($dsNS, 'ds:Signature');
        $signature->setAttribute('Id', $sigId);
        $root->appendChild($signature);

        // ===================== <ds:SignedInfo> =====================
        $signedInfo = $dom->createElementNS($dsNS, 'ds:SignedInfo');
        $signature->appendChild($signedInfo);

        // CanonicalizationMethod = EXC-C14N (obligatorio en práctica real con AGE)
        $c14n = $dom->createElementNS($dsNS, 'ds:CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($c14n);

        // SignatureMethod = RSA-SHA256 (el recomendado)
        $sigMethod = $dom->createElementNS($dsNS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($sigMethod);

        // ===================== REFERENCE 1: SignedProperties (primero, como en Facturae y como esperan muchos validadores) =====================
        $refProps = $dom->createElementNS($dsNS, 'ds:Reference');
        $refProps->setAttribute('URI', '#' . $signedPropsId);
        $refProps->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $signedInfo->appendChild($refProps);

        $transformsProps = $dom->createElementNS($dsNS, 'ds:Transforms');
        $refProps->appendChild($transformsProps);
        $trPropsC14n = $dom->createElementNS($dsNS, 'ds:Transform');
        $trPropsC14n->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transformsProps->appendChild($trPropsC14n);

        $dmProps = $dom->createElementNS($dsNS, 'ds:DigestMethod');
        $dmProps->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $refProps->appendChild($dmProps);

        $dvProps = $dom->createElementNS($dsNS, 'ds:DigestValue');
        $refProps->appendChild($dvProps);

        // ===================== REFERENCE 2: Documento (enveloped) =====================
        $refDoc = $dom->createElementNS($dsNS, 'ds:Reference');
        $refDoc->setAttribute('URI', '');
        $refDoc->setAttribute('Id', $sigId . '-ref0'); // Crea un ID único para esta referencia
        $signedInfo->appendChild($refDoc);

        $transforms = $dom->createElementNS($dsNS, 'ds:Transforms');
        $refDoc->appendChild($transforms);

        $trEnv = $dom->createElementNS($dsNS, 'ds:Transform');
        $trEnv->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($trEnv);

        $dmDoc = $dom->createElementNS($dsNS, 'ds:DigestMethod');
        $dmDoc->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $refDoc->appendChild($dmDoc);

        $dvDoc = $dom->createElementNS($dsNS, 'ds:DigestValue');
        $refDoc->appendChild($dvDoc);

        // ===================== <ds:Object> + <xades:QualifyingProperties> =====================
        $object = $dom->createElementNS($dsNS, 'ds:Object');
        $signature->appendChild($object);

        $qualProps = $dom->createElementNS($xadesNS, 'xades:QualifyingProperties');
        $qualProps->setAttribute('Target', '#' . $sigId);
        $object->appendChild($qualProps);

        $signedPropsNode = $dom->createElementNS($xadesNS, 'xades:SignedProperties');
        $signedPropsNode->setAttribute('Id', $signedPropsId);
        $qualProps->appendChild($signedPropsNode);

        $sigProps = $dom->createElementNS($xadesNS, 'xades:SignedSignatureProperties');
        $signedPropsNode->appendChild($sigProps);

        // SigningTime (UTC)
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $sigTime = $dom->createElementNS($xadesNS, 'xades:SigningTime', $now->format('Y-m-d\TH:i:s\Z'));
        $sigProps->appendChild($sigTime);

        // SigningCertificate + CertDigest SHA1
        $signingCert = $dom->createElementNS($xadesNS, 'xades:SigningCertificate');
        $sigProps->appendChild($signingCert);

        $certNode = $dom->createElementNS($xadesNS, 'xades:Cert');
        $signingCert->appendChild($certNode);

        $certDigestNode = $dom->createElementNS($xadesNS, 'xades:CertDigest');
        $certNode->appendChild($certDigestNode);

        $dmCert = $dom->createElementNS($dsNS, 'ds:DigestMethod');
        $dmCert->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $certDigestNode->appendChild($dmCert);

        $dvCert = $dom->createElementNS($dsNS, 'ds:DigestValue', $certSha1);
        $certDigestNode->appendChild($dvCert);

        // IssuerSerial
        $issuerSerial = $dom->createElementNS($xadesNS, 'xades:IssuerSerial');
        $certNode->appendChild($issuerSerial);

        $issuerNameNode = $dom->createElementNS($dsNS, 'ds:X509IssuerName', $issuerName);
        $issuerSerial->appendChild($issuerNameNode);

        $serialNode = $dom->createElementNS($dsNS, 'ds:X509SerialNumber', $serialNumber);
        $issuerSerial->appendChild($serialNode);

        // SignaturePolicyIdentifier (EPES AGE)
        $policy = $dom->createElementNS($xadesNS, 'xades:SignaturePolicyIdentifier');
        $sigProps->appendChild($policy);

        $policyId = $dom->createElementNS($xadesNS, 'xades:SignaturePolicyId');
        $policy->appendChild($policyId);

        $sigPolicyId = $dom->createElementNS($xadesNS, 'xades:SigPolicyId');
        $policyId->appendChild($sigPolicyId);

        $identifier = $dom->createElementNS($xadesNS, 'xades:Identifier', 'urn:oid:2.16.724.1.3.1.1.2.1.9');
        $identifier->setAttribute('Qualifier', 'OIDAsURN');
        $sigPolicyId->appendChild($identifier);

        $description = $dom->createElementNS($xadesNS, 'xades:Description', 'Política de firma electrónica utilizada en la Administración General del Estado');
        $sigPolicyId->appendChild($description);

        $policyHash = $dom->createElementNS($xadesNS, 'xades:SigPolicyHash');
        $policyId->appendChild($policyHash);

        $dmPolicy = $dom->createElementNS($dsNS, 'ds:DigestMethod');
        $dmPolicy->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $policyHash->appendChild($dmPolicy);

        $dvPolicy = $dom->createElementNS($dsNS, 'ds:DigestValue', 'G7roucf600+f03r/o0bAOQ6WAs0=');
        $policyHash->appendChild($dvPolicy);

        // SPURI (obligatorio para que pase tu verificarFirmaXAdES y Válide)
        $qualifiers = $dom->createElementNS($xadesNS, 'xades:SigPolicyQualifiers');
        $policyId->appendChild($qualifiers);

        $qualifier = $dom->createElementNS($xadesNS, 'xades:SigPolicyQualifier');
        $qualifiers->appendChild($qualifier);

        $spuri = $dom->createElementNS($xadesNS, 'xades:SPURI', 'https://sede.administracion.gob.es/politica_de_firma_anexo_1.pdf');
        $qualifier->appendChild($spuri);

        // ===================== SignedDataObjectProperties (OBLIGATORIO) =====================
        $dataObjProps = $dom->createElementNS($xadesNS, 'xades:SignedDataObjectProperties');
        $signedPropsNode->appendChild($dataObjProps); // Añádelo junto a SignedSignatureProperties

        $dataObjFmt = $dom->createElementNS($xadesNS, 'xades:DataObjectFormat');
        // Apunta al Id que creamos en el paso 1
        $dataObjFmt->setAttribute('ObjectReference', '#' . $sigId . '-ref0');
        $dataObjProps->appendChild($dataObjFmt);

        $objId = $dom->createElementNS($xadesNS, 'xades:ObjectIdentifier');
        $dataObjFmt->appendChild($objId);

        // Este OID (1.2.840.10003.5.109.10) y MimeType (text/xml) son del ejemplo de la AEAT
        $identifier = $dom->createElementNS($xadesNS, 'xades:Identifier', 'urn:oid:1.2.840.10003.5.109.10');
        $objId->appendChild($identifier);
        $objId->appendChild($dom->createElementNS($xadesNS, 'xades:Description')); // Vacío, como en el ejemplo

        $mime = $dom->createElementNS($xadesNS, 'xades:MimeType', 'text/xml');
        $dataObjFmt->appendChild($mime);

        $encoding = $dom->createElementNS($xadesNS, 'xades:Encoding', 'UTF-8');
        $dataObjFmt->appendChild($encoding);

        // ===================== CALCULAR DIGESTS =====================
        // 1. SignedProperties (EXC-C14N)
        $propsC14n = $signedPropsNode->C14N(false, false);
        $propsSha256 = base64_encode(hash('sha256', $propsC14n, true));
        $dvProps->nodeValue = $propsSha256;

        // 2. Documento (quitamos temporalmente la firma para aplicar enveloped transform)
        $root->removeChild($signature);
        $docC14n = $root->C14N(false, false);
        $docSha256 = base64_encode(hash('sha256', $docC14n, true));
        $dvDoc->nodeValue = $docSha256;
        $root->appendChild($signature); // restaurar

        // ===================== FIRMAMOS SignedInfo =====================
        $signedInfoC14n = $signedInfo->C14N(false, false);

        $signatureBin = '';
        openssl_sign($signedInfoC14n, $signatureBin, $privateKey, OPENSSL_ALGO_SHA256);

        $signatureB64 = base64_encode($signatureBin);

        $signatureValue = $dom->createElementNS($dsNS, 'ds:SignatureValue', $signatureB64);
        $signature->insertBefore($signatureValue, $object); // justo después de SignedInfo

        // ===================== KeyInfo con X509Certificate =====================
        $keyInfo = $dom->createElementNS($dsNS, 'ds:KeyInfo');
        $signature->insertBefore($keyInfo, $object);

        $x509Data = $dom->createElementNS($dsNS, 'ds:X509Data');
        $keyInfo->appendChild($x509Data);

        $x509CertNode = $dom->createElementNS($dsNS, 'ds:X509Certificate', $certClean);
        $x509Data->appendChild($x509CertNode);

        // ===================== FIN =====================
        return $dom->saveXML();
    }

    /**
     * Verifica una firma XAdES Enveloped según specs AEAT VeriFactu.
     *
     * @param string $xmlSigned XML firmado completo.
     * @return bool True si la firma es válida.
     * @throws Exception Si hay error en la verificación.
     */
    function verificarFirmaXAdES(string $xmlSigned): bool
    {
        throw new Exception("La función de verificación no está implementada completamente y requeriría una librería robusta de validación XAdES.");
    }
}
