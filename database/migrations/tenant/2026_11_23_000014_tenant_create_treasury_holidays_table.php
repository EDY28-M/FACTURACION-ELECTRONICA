<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent tenant migration: national holidays (feriados) used to compute business days for the
 * SPOT (detraction) deposit deadline (5th business day).
 *
 * Seeded with the 16 national feriados of 2026 and 2027 (DL 713 art. 6 as amended by Ley 31822). Días no
 * laborables of the public sector are NOT feriados: for tax purposes they are business days (DS 042-2025-PCM
 * art. 1.2 and DS 075-2026-PCM art. 1.2). They are stored with `is_business_day_override = true` for
 * documentation only and are never treated as holidays.
 *
 * 2027 Semana Santa (25 and 26 March) is computed from Easter (28 March 2027); the official 2027 list was not
 * published when this seed was written. Review it when the decree is published.
 * Source: Engram research "Peru feriados 2026-27, dias habiles SUNAT" (treasury/research-holidays-ple).
 */
class TenantCreateTreasuryHolidaysTable extends Migration
{
    public const SOURCE_FERIADOS = 'https://www.gob.pe/feriados';
    public const SOURCE_DL_713 = 'https://www.gob.pe/feriados';
    public const SOURCE_LEY_31788 = 'https://busquedas.elperuano.pe/dispositivo/NL/2187453-1';
    public const SOURCE_LEY_31822 = 'https://busquedas.elperuano.pe/dispositivo/NL/2194316-1';
    public const SOURCE_LEY_31530 = 'https://busquedas.elperuano.pe/dispositivo/NL/2089960-2';
    public const SOURCE_LEY_31381 = 'https://busquedas.elperuano.pe/dispositivo/NL/2026913-1';
    public const SOURCE_DS_042_2025 = 'https://busquedas.elperuano.pe/dispositivo/NL/2387094-1';
    public const SOURCE_DS_075_2026 = 'https://busquedas.elperuano.pe/dispositivo/NL/2516156-2';

    public function up()
    {
        if (!Schema::hasTable('treasury_holidays')) {
            Schema::create('treasury_holidays', function (Blueprint $table) {
                $table->increments('id');
                $table->date('date')->unique();
                $table->string('name', 120);
                $table->string('legal_reference', 160)->nullable();
                $table->string('source_url', 255)->nullable();
                $table->string('scope', 20)->default('national');
                // true = día no laborable declared hábil for tax purposes: documentation only, never a holiday.
                $table->boolean('is_business_day_override')->default(false);
                $table->timestamps();
            });
        }

        $now = date('Y-m-d H:i:s');
        foreach (static::seedRows() as $row) {
            if (DB::table('treasury_holidays')->where('date', $row['date'])->exists()) {
                continue;
            }

            DB::table('treasury_holidays')->insert($row + [
                'scope' => 'national',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('treasury_holidays');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function seedRows(): array
    {
        $rows = [];
        $fixed = [
            ['01-01', 'Año Nuevo', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['05-01', 'Día del Trabajo', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['06-07', 'Batalla de Arica y Día de la Bandera', 'Ley 31788', self::SOURCE_LEY_31788],
            ['06-29', 'San Pedro y San Pablo', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['07-23', 'Día de la Fuerza Aérea del Perú', 'Ley 31822', self::SOURCE_LEY_31822],
            ['07-28', 'Fiestas Patrias', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['07-29', 'Fiestas Patrias', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['08-06', 'Batalla de Junín', 'Ley 31530', self::SOURCE_LEY_31530],
            ['08-30', 'Santa Rosa de Lima', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['10-08', 'Combate de Angamos', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['11-01', 'Todos los Santos', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['12-08', 'Inmaculada Concepción', 'DL 713 art. 6', self::SOURCE_DL_713],
            ['12-09', 'Batalla de Ayacucho', 'Ley 31381', self::SOURCE_LEY_31381],
            ['12-25', 'Navidad', 'DL 713 art. 6', self::SOURCE_DL_713],
        ];
        // Semana Santa: Thursday and Friday before Easter (5 April 2026, 28 March 2027).
        $easter = [
            2026 => ['2026-04-02', '2026-04-03'],
            2027 => ['2027-03-25', '2027-03-26'],
        ];

        foreach ([2026, 2027] as $year) {
            foreach ($fixed as [$monthDay, $name, $reference, $url]) {
                $rows[] = [
                    'date' => $year . '-' . $monthDay,
                    'name' => $name,
                    'legal_reference' => $reference,
                    'source_url' => $url,
                    'is_business_day_override' => false,
                ];
            }
            foreach ($easter[$year] as $index => $date) {
                $rows[] = [
                    'date' => $date,
                    'name' => $index === 0 ? 'Jueves Santo' : 'Viernes Santo',
                    'legal_reference' => 'DL 713 art. 6',
                    'source_url' => self::SOURCE_DL_713,
                    'is_business_day_override' => false,
                ];
            }
        }

        // Días no laborables (sector público): hábiles para fines tributarios. Documentation only.
        $rows[] = [
            'date' => '2026-01-02',
            'name' => 'Día no laborable compensable (sector público) - hábil para fines tributarios',
            'legal_reference' => 'DS 042-2025-PCM art. 1.2',
            'source_url' => self::SOURCE_DS_042_2025,
            'is_business_day_override' => true,
        ];
        $rows[] = [
            'date' => '2026-07-27',
            'name' => 'Día no laborable compensable (sector público) - hábil para fines tributarios',
            'legal_reference' => 'DS 075-2026-PCM art. 1.2',
            'source_url' => self::SOURCE_DS_075_2026,
            'is_business_day_override' => true,
        ];

        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $rows;
    }
}
