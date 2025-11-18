<?php

namespace josemmo\Verifactu\Services;

use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\FiscalIdentifier;

class CustomAeatClient extends AeatClient
{
    /**
     * Obtiene los objetos ComputerSystem y FiscalIdentifier usados por el cliente
     *
     * @return array{system: ComputerSystem, taxpayer: FiscalIdentifier}
     */
    public function getSystemAndTaxpayer(): array
    {
        return [
            'system' => $this->system,
            'taxpayer' => $this->taxpayer,
        ];
    }
}
