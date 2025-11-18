<?php

namespace josemmo\Verifactu\Services;

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\FiscalIdentifier;

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
}
