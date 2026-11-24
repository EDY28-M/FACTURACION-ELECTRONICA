<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent tenant migration (Treasury Phase 7, cheques y letras).
 *
 * - `treasury_instruments`: cheques (issued / received) and bills of exchange (payable / receivable). Money effects
 *   are NOT stored here: collecting or paying creates a `treasury_movement` (linked by `movement_id`).
 * - `treasury_instrument_events`: status history (audit trail).
 * - Seeds the versioned legal periods in `treasury_tax_rates` (never hardcoded in code) and two treasury concepts
 *   used by the movements created from instruments.
 *
 * Legal values come from docs/plans/tesoreria-investigacion-sunat.md (Ley 27287, Ley de Titulos Valores). That
 * document is a secondary source for these facts, so every row is flagged [REVISAR] until counsel confirms it.
 */
class TenantCreateTreasuryInstrumentsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_instruments')) {
            Schema::create('treasury_instruments', function (Blueprint $table) {
                $table->increments('id');
                // cheque_issued | cheque_received | bill_payable | bill_receivable
                $table->string('type', 20);
                $table->string('number', 40);
                $table->unsignedInteger('establishment_id');
                // Drawee bank of a cheque / bank of the bill.
                $table->unsignedInteger('bank_id')->nullable();
                // Own account: drawn-on account (cheque_issued), deposit account (received) or payment account (payable).
                $table->unsignedInteger('bank_account_id')->nullable();
                $table->unsignedInteger('person_id')->nullable();
                $table->string('drawer_name', 255)->nullable(); // girador / librador
                $table->string('payee_name', 255)->nullable(); // beneficiario / tenedor
                $table->date('issue_date');
                $table->date('due_date');
                $table->boolean('is_deferred')->default(false); // cheque de pago diferido
                $table->decimal('amount', 14, 2);
                $table->char('currency_type_id', 3)->default('PEN');
                // issued | delivered | deposited | collected | bounced | protested | voided
                $table->string('status', 12);
                // Computed deadlines (see InstrumentDeadlines). NULL when the rule does not apply to the type.
                $table->date('presentation_deadline')->nullable();
                $table->date('protest_deadline')->nullable();
                $table->boolean('deadline_is_estimate')->default(false);
                $table->unsignedInteger('movement_id')->nullable();
                $table->string('source_type', 150)->nullable(); // optional link: Document | Purchase
                $table->unsignedInteger('source_id')->nullable();
                $table->string('notes', 500)->nullable();
                // type|bank_id|number while the instrument is not voided; NULL afterwards. UNIQUE = no duplicate live number.
                $table->string('active_key', 120)->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->unsignedInteger('voided_by')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->unsignedInteger('user_id');
                $table->timestamps();

                $table->unique('active_key', 'ti_active_key_unique');
                $table->index(['type', 'status'], 'ti_type_status_idx');
                $table->index(['establishment_id', 'due_date'], 'ti_est_due_idx');
                $table->index('due_date', 'ti_due_idx');
                $table->index('presentation_deadline', 'ti_presentation_idx');
                $table->index('protest_deadline', 'ti_protest_idx');
                $table->index(['source_type', 'source_id'], 'ti_source_idx');
                $table->index('movement_id', 'ti_movement_idx');
            });
        }

        if (!Schema::hasTable('treasury_instrument_events')) {
            Schema::create('treasury_instrument_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('instrument_id');
                $table->string('action', 20)->default('transition'); // created | updated | transition
                $table->string('from_status', 12)->nullable();
                $table->string('to_status', 12);
                $table->date('date');
                $table->unsignedInteger('user_id');
                $table->string('notes', 500)->nullable();
                $table->unsignedInteger('movement_id')->nullable();
                $table->timestamps();

                $table->index('instrument_id', 'tie_instrument_idx');
                $table->foreign('instrument_id')->references('id')->on('treasury_instruments')->onDelete('restrict');
            });
        }

        $this->seedRates();
        $this->seedConcepts();
    }

    public function down()
    {
        Schema::dropIfExists('treasury_instrument_events');
        Schema::dropIfExists('treasury_instruments');

        if (Schema::hasTable('treasury_tax_rates')) {
            DB::table('treasury_tax_rates')
                ->whereIn('tax', ['cheque_presentation_days', 'cheque_deferred_max_days', 'bill_protest_days'])
                ->delete();
        }
    }

    private function seedRates(): void
    {
        if (!Schema::hasTable('treasury_tax_rates')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = [
            [
                'tax' => 'cheque_presentation_days',
                'amount' => 30,
                'legal_reference' => 'Ley 27287 art. 207: presentacion del cheque dentro de 30 dias desde su emision (plazo de la misma plaza) [REVISAR: otros plazos por plaza no cargados; para el cheque de pago diferido se cuenta desde la fecha de pago]',
            ],
            [
                'tax' => 'cheque_deferred_max_days',
                'amount' => 30,
                'legal_reference' => 'Ley 27287: plazo maximo del cheque de pago diferido, 30 dias desde su emision [REVISAR]',
            ],
            [
                'tax' => 'bill_protest_days',
                'amount' => 15,
                'legal_reference' => 'Ley 27287: protesto por falta de pago dentro de los 15 dias posteriores al vencimiento [REVISAR: dias utiles o calendario; ver config treasury.instruments.protest_day_type]',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('treasury_tax_rates')
                ->where('tax', $row['tax'])
                ->whereNull('code')
                ->where('valid_from', '2000-01-01')
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('treasury_tax_rates')->insert($row + [
                'code' => null,
                'rate' => null,
                'min_operation' => null,
                'valid_from' => '2000-01-01',
                'valid_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedConcepts(): void
    {
        if (!Schema::hasTable('treasury_concepts')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ([
            ['type' => 'income', 'description' => 'Cobro de cheques y letras'],
            ['type' => 'expense', 'description' => 'Pago de cheques y letras'],
        ] as $concept) {
            $exists = DB::table('treasury_concepts')
                ->where('type', $concept['type'])
                ->where('description', $concept['description'])
                ->exists();
            if (!$exists) {
                DB::table('treasury_concepts')->insert($concept + [
                    'pcge_account' => null,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
