<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Lee filas de un archivo Excel usando streaming.
 * Nunca carga el archivo completo en memoria.
 * 
 * Single Responsibility: Solo lee filas del Excel.
 */
final class ExcelRowReader
{
    private string $filePath;
    private ?Reader $reader = null;
    
    /** @var array<string> */
    private array $headers = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Genera filas del Excel como arrays asociativos.
     * Usa un Generator para streaming real sin cargar todo en memoria.
     * 
     * @return Generator<int, array> rowIndex => rowData
     */
    public function readRows(): Generator
    {
        $this->reader = new Reader(new Options());
        $this->reader->open($this->filePath);

        try {
            foreach ($this->reader->getSheetIterator() as $sheet) {
                $rowIndex = 0;
                
                foreach ($sheet->getRowIterator() as $row) {
                    $rowData = $row->toArray();
                    $rowIndex++;
                    
                    // Primera fila = headers
                    if ($rowIndex === 1) {
                        $this->headers = $this->normalizeHeaders($rowData);
                        continue;
                    }
                    
                    // Convertir a array asociativo
                    yield $rowIndex => $this->rowToAssociative($rowData);
                }
                
                // Solo procesamos la primera hoja
                break;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Estima el total de filas basándose en el tamaño del archivo.
     */
    public function estimateTotalRows(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }

        $fileSize = filesize($this->filePath);
        // ~70-100 bytes por fila en XLSX comprimido
        return (int) ceil($fileSize / 80);
    }

    public function getFileSizeMb(): float
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }

        return round(filesize($this->filePath) / 1024 / 1024, 2);
    }

    public function close(): void
    {
        if ($this->reader !== null) {
            $this->reader->close();
            $this->reader = null;
        }
    }

    /**
     * Normaliza los headers a lowercase y sin espacios.
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            if ($header === null) {
                return '';
            }
            return strtolower(trim(str_replace(' ', '_', (string) $header)));
        }, $headers);
    }

    /**
     * Convierte una fila a array asociativo usando los headers.
     */
    private function rowToAssociative(array $rowData): array
    {
        $assoc = [];
        foreach ($this->headers as $index => $header) {
            if ($header !== '') {
                $assoc[$header] = $rowData[$index] ?? null;
            }
        }
        return $assoc;
    }
}
