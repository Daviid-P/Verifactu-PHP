<?php

namespace josemmo\Verifactu\Services;

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;
use ReflectionProperty;


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
}
