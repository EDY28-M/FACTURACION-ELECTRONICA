<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads SUNAT Catalogo 25 (UNSPSC v14_0801) into the system database.
 * Source: official SUNAT file CCNU_MOD_2.xlsm (cpe.sunat.gob.pe/informacion_general/codigoproducto),
 * last modified 2026-07-10, converted to database/data/sunat_catalogo25.csv.gz (columns code,description,level).
 * To refresh: convert the new official file to the same CSV, replace the .gz and rerun this command.
 */
class ImportSunatProductCodes extends Command
{
    protected $signature = 'sunat:import-product-codes {--path= : CSV or CSV.GZ file (code,description,level)}';
    protected $description = 'Import SUNAT product codes (Catalogo 25) into the system database';

    public function handle()
    {
        $path = $this->option('path') ?: database_path('data/sunat_catalogo25.csv.gz');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $gz = substr($path, -3) === '.gz';
        $h = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $read = function () use ($h, $gz) {
            return $gz ? gzgets($h) : fgets($h);
        };

        $header = str_getcsv(trim((string) $read()), ',', '"', '');
        if (array_slice($header, 0, 3) !== ['code', 'description', 'level']) {
            $this->error('Unexpected header, expected: code,description,level');
            return 1;
        }

        $conn = DB::connection('system');
        $total = 0;
        $conn->transaction(function () use ($conn, $read, &$total) {
            $conn->table('sunat_product_codes')->delete();
            $chunk = [];
            while (($line = $read()) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $r = str_getcsv($line, ',', '"', '');
                if (!preg_match('/^\d{8}$/', $r[0] ?? '') || !in_array($r[2] ?? '', ['class', 'product'], true)) {
                    throw new \RuntimeException("Invalid row: {$line}");
                }
                $chunk[] = ['code' => $r[0], 'description' => $r[1], 'level' => $r[2]];
                if (count($chunk) === 1000) {
                    $conn->table('sunat_product_codes')->insert($chunk);
                    $total += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk) {
                $conn->table('sunat_product_codes')->insert($chunk);
                $total += count($chunk);
            }
        });

        $this->info("Imported {$total} product codes.");
        return 0;
    }
}
