<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates core Phase 1 Treasury tables.
 *
 * Design note (§3.2): uses a dedicated `treasury_concepts` table rather than overloading
 * Finance `income_reasons` / Expense `expense_reasons` so treasury movements have a unified
 * bilingual inflow/outflow catalog with optional PCGE account mapping and zero coupling to
 * legacy Finance/Expense reason tables.
 */
class TenantCreateTreasuryCoreTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_concepts')) {
            Schema::create('treasury_concepts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type', 20); // income | expense
                $table->string('description', 255);
                $table->string('pcge_account', 20)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        $this->seedConcepts();

        if (!Schema::hasTable('treasury_movements')) {
            Schema::create('treasury_movements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('establishment_id');
                $table->date('date');
                $table->string('kind', 30);
                $table->tinyInteger('direction'); // +1 inflow, -1 outflow
                $table->string('destination_type', 150);
                $table->unsignedInteger('destination_id');
                $table->char('currency_type_id', 3);
                $table->decimal('amount', 14, 2);
                $table->decimal('exchange_rate_purchase', 10, 4)->nullable();
                $table->decimal('exchange_rate_sale', 10, 4)->nullable();
                $table->decimal('exchange_rate_used', 10, 4)->nullable();
                $table->decimal('amount_pen', 14, 2);
                $table->char('payment_method_type_id', 2)->nullable();
                $table->char('sunat_payment_code', 3)->nullable();
                $table->string('operation_number', 60)->nullable();
                $table->unsignedInteger('counterparty_person_id')->nullable();
                $table->string('counterparty_document', 20)->nullable();
                $table->string('counterparty_name', 255)->nullable();
                $table->unsignedInteger('treasury_concept_id')->nullable();
                $table->string('description', 500);
                $table->string('transfer_group', 36)->nullable();
                $table->unsignedInteger('related_movement_id')->nullable();
                $table->string('source_type', 150)->nullable();
                $table->unsignedInteger('source_id')->nullable();
                $table->dateTime('reconciled_at')->nullable();
                $table->unsignedInteger('bank_statement_line_id')->nullable();
                $table->string('state', 10)->default('active'); // active | voided
                $table->dateTime('voided_at')->nullable();
                $table->unsignedInteger('voided_by')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->unsignedInteger('user_id');
                $table->timestamps();

                $table->index(['destination_type', 'destination_id', 'date'], 'tm_dest_date_idx');
                $table->index(['establishment_id', 'date'], 'tm_est_date_idx');
                $table->index(['source_type', 'source_id'], 'tm_source_idx');
                $table->index('related_movement_id', 'tm_related_movement_idx');
                $table->index('transfer_group', 'tm_transfer_group_idx');
                $table->index('state', 'tm_state_idx');
            });
        }

        if (!Schema::hasTable('payment_evidences')) {
            Schema::create('payment_evidences', function (Blueprint $table) {
                $table->increments('id');
                $table->string('payable_type', 150);
                $table->unsignedInteger('payable_id');
                $table->char('sunat_payment_code', 3);
                $table->string('operation_number', 60)->nullable();
                $table->unsignedInteger('bank_id')->nullable();
                $table->unsignedInteger('bank_account_id')->nullable();
                $table->date('operation_date');
                $table->string('payer_or_beneficiary_document', 20)->nullable();
                $table->string('payer_or_beneficiary_name', 255)->nullable();
                $table->boolean('third_party_payment')->default(false);
                $table->boolean('third_party_reported_to_sunat')->default(false);
                $table->string('bancarization_exception', 30)->nullable();
                $table->timestamps();

                $table->unique(['payable_type', 'payable_id'], 'pe_payable_unique');
            });
        }

        if (!Schema::hasTable('treasury_tax_rates')) {
            Schema::create('treasury_tax_rates', function (Blueprint $table) {
                $table->increments('id');
                $table->string('tax', 40);
                $table->string('code', 20)->nullable();
                $table->decimal('rate', 8, 5)->nullable();
                $table->decimal('amount', 14, 2)->nullable();
                $table->decimal('min_operation', 14, 2)->nullable();
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('legal_reference', 255);
                $table->timestamps();

                $table->index(['tax', 'code', 'valid_from'], 'ttr_tax_code_valid_idx');
            });
        }

        $this->seedTaxRates();
    }

    private function seedConcepts(): void
    {
        $now = date('Y-m-d H:i:s');
        $concepts = [
            ['type' => 'income', 'description' => 'Préstamo recibido', 'pcge_account' => '451'],
            ['type' => 'income', 'description' => 'Aporte de capital', 'pcge_account' => '501'],
            ['type' => 'income', 'description' => 'Intereses ganados', 'pcge_account' => '772'],
            ['type' => 'expense', 'description' => 'Comisión bancaria', 'pcge_account' => '6391'],
            ['type' => 'expense', 'description' => 'Pago de tributos', 'pcge_account' => '401'],
            ['type' => 'expense', 'description' => 'Pago de planilla', 'pcge_account' => '411'],
            ['type' => 'expense', 'description' => 'Pago de servicios', 'pcge_account' => '636'],
            ['type' => 'income', 'description' => 'Otros ingresos', 'pcge_account' => '759'],
            ['type' => 'expense', 'description' => 'Otros egresos', 'pcge_account' => '659'],
        ];

        foreach ($concepts as $concept) {
            $exists = DB::table('treasury_concepts')
                ->where('type', $concept['type'])
                ->where('description', $concept['description'])
                ->exists();

            if (!$exists) {
                DB::table('treasury_concepts')->insert($concept + [
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedTaxRates(): void
    {
        $now = date('Y-m-d H:i:s');

        $rates = [
            // IGV Retention (CRE) - 3% for operations > S/ 700
            [
                'tax' => 'retention_igv',
                'code' => null,
                'rate' => 0.03000,
                'amount' => null,
                'min_operation' => 700.00,
                'valid_from' => '2014-03-01',
                'valid_to' => null,
                'legal_reference' => 'RS 033-2014/SUNAT (3% operaciones > S/ 700) [REVISAR: anuncio SUNAT 2026-09-11]',
            ],
            // ITF - 0.005%
            [
                'tax' => 'itf',
                'code' => null,
                'rate' => 0.00005,
                'amount' => null,
                'min_operation' => null,
                'valid_from' => '2011-04-01',
                'valid_to' => null,
                'legal_reference' => 'Ley 29667 Art. 10 TUO DS 150-2007-EF (0.005%)',
            ],
            // Bancarization thresholds (Ley 28194 / DL 1529 since 2022-04-01)
            [
                'tax' => 'bancarization_threshold_pen',
                'code' => null,
                'rate' => null,
                'amount' => 2000.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529 Art. 4 TUO Ley 28194 (S/ 2,000 desde 2022-04-01)',
            ],
            [
                'tax' => 'bancarization_threshold_usd',
                'code' => null,
                'rate' => null,
                'amount' => 500.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529 Art. 4 TUO Ley 28194 (US$ 500 desde 2022-04-01)',
            ],
            // IGV Perception (CPE) - 2%, 1%, 0.5%
            [
                'tax' => 'perception_igv',
                'code' => 'general',
                'rate' => 0.02000,
                'amount' => null,
                'min_operation' => null,
                'valid_from' => '2013-07-01',
                'valid_to' => null,
                'legal_reference' => 'Ley 29173 / DS 091-2013-EF (2% venta interna bienes Apendice 1)',
            ],
            [
                'tax' => 'perception_igv',
                'code' => 'fuel',
                'rate' => 0.01000,
                'amount' => null,
                'min_operation' => null,
                'valid_from' => '2013-07-01',
                'valid_to' => null,
                'legal_reference' => 'Ley 29173 (1% combustibles liquidos)',
            ],
            [
                'tax' => 'perception_igv',
                'code' => 'agent',
                'rate' => 0.00500,
                'amount' => null,
                'min_operation' => null,
                'valid_from' => '2013-07-01',
                'valid_to' => null,
                'legal_reference' => 'Ley 29173 (0.5% cliente agente de percepcion)',
            ],
            // SPOT Detractions (Catalog 54 / RS 183-2004/SUNAT & RS 071-2018 & RS 086-2025)
            // Marked for review following SUNAT 2026-09-11 rate update announcement.
            ['tax' => 'detraction', 'code' => '001', 'rate' => 0.10000, 'amount' => null, 'min_operation' => null, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 1 Azucar y melaza (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '003', 'rate' => 0.10000, 'amount' => null, 'min_operation' => null, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 1 Alcohol etilico (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '004', 'rate' => 0.04000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 2 Recursos hidrobiologicos (4%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '005', 'rate' => 0.04000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 2 Maiz amarillo duro (4%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '012', 'rate' => 0.12000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 071-2018 Anexo 3 Intermediacion laboral y tercerizacion (12%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '019', 'rate' => 0.10000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Arrendamiento de bienes (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '020', 'rate' => 0.12000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 071-2018 Anexo 3 Mantenimiento y reparacion bienes muebles (12%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '021', 'rate' => 0.10000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Movimiento de carga (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '022', 'rate' => 0.12000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 071-2018 Anexo 3 Otros servicios empresariales (12%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '024', 'rate' => 0.10000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Comision mercantil (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '025', 'rate' => 0.10000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Fabricacion de bienes por encargo (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '026', 'rate' => 0.10000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Servicio de transporte de personas (10%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '027', 'rate' => 0.04000, 'amount' => null, 'min_operation' => 400.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 073-2006 Transporte de bienes por via terrestre (4% > S/ 400) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '030', 'rate' => 0.04000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Anexo 3 Contratos de construccion (4%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '037', 'rate' => 0.12000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 071-2018 Anexo 3 Demas servicios gravados con IGV (12%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '040', 'rate' => 0.04000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'RS 183-2004 Bien inmueble gravado con IGV (4%) [REVISAR: anuncio SUNAT 2026-09-11]'],
            ['tax' => 'detraction', 'code' => '045', 'rate' => 0.12000, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2025-04-01', 'valid_to' => null, 'legal_reference' => 'RS 086-2025 Anexo 3 Beneficio de minerales metalicos (12%) [REVISAR: anuncio SUNAT 2026-09-11]'],
        ];

        foreach ($rates as $rate) {
            $query = DB::table('treasury_tax_rates')
                ->where('tax', $rate['tax'])
                ->where('valid_from', $rate['valid_from']);

            if ($rate['code'] === null) {
                $query->whereNull('code');
            } else {
                $query->where('code', $rate['code']);
            }

            if (!$query->exists()) {
                DB::table('treasury_tax_rates')->insert($rate + [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('treasury_tax_rates');
        Schema::dropIfExists('payment_evidences');
        Schema::dropIfExists('treasury_movements');
        Schema::dropIfExists('treasury_concepts');
    }
}
