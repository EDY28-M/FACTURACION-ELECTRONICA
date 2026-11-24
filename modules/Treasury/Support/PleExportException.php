<?php

namespace Modules\Treasury\Support;

/**
 * Raised when the PLE files cannot be generated without guessing data (missing Tabla 1 / Tabla 3 codes, no RUC...).
 */
class PleExportException extends \RuntimeException
{
    /** @var array<int, array{code: string, message: string, items: array<int, string>}> */
    private $blockers;

    /**
     * @param array<int, array{code: string, message: string, items: array<int, string>}> $blockers
     */
    public function __construct(array $blockers)
    {
        $this->blockers = $blockers;
        parent::__construct('No se puede generar el PLE: ' . implode(' ', array_map(function ($blocker) {
            return $blocker['message'];
        }, $blockers)));
    }

    /**
     * @return array<int, array{code: string, message: string, items: array<int, string>}>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
