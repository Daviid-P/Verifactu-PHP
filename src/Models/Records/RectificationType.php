<?php
namespace josemmo\Verifactu\Models\Records;

/**
 * Enumeración de tipos de rectificación
 *
 * Según la normativa VERI*FACTU actualizada de 2024,
 * las facturas rectificativas deben especificar el tipo de rectificación
 */
enum RectificationType: string {
    /**
     * Por sustitución
     *
     * Se emite una factura rectificativa que sustituye completamente
     * a la factura original. La factura original queda anulada.
     */
    case PorSustitucion = 'S';

    /**
     * Por diferencias
     *
     * Se emite una factura rectificativa que complementa la factura original,
     * corrigiendo únicamente las diferencias en importes o datos específicos.
     * La factura original sigue siendo válida.
     */
    case PorDiferencias = 'I';
}
