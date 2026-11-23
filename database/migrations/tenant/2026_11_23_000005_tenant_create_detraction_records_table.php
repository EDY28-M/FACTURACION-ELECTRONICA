<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration creating `detraction_records` (SPOT control, Treasury Phase 4).
 *
 * receivable: I am the supplier and my customer must deposit the detraction in my Banco de la Nacion account.
 * payable: I am the buyer and I must deposit the detraction in my supplier's Banco de la Nacion account.
 */
class TenantCreateDetractionRecordsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('detraction_records')) {
            Schema::create('detraction_records', function (Blueprint $table) {
                $table->increments('id');
                $table->string('side', 12); // receivable | payable
                $table->string('source_type', 120); // Document | Purchase
                $table->unsignedInteger('source_id');
                $table->unsignedInteger('establishment_id')->nullable();
                $table->unsignedInteger('user_id')->nullable(); // owner of the source document (non-admin scoping)
                $table->string('code', 3)->nullable(); // Catalogo 54
                $table->decimal('rate', 8, 5)->nullable();
                $table->string('currency_type_id', 3)->default('PEN');
                $table->decimal('base_amount', 14, 2)->default(0); // document total in its own currency
                $table->decimal('amount_pen', 14, 2)->default(0); // detraction amount, always PEN
                $table->string('bn_account_number', 30)->nullable();
                $table->date('registry_date')->nullable(); // payable: Registro de Compras entry date
                $table->date('due_date')->nullable();
                $table->date('deposited_at')->nullable();
                $table->string('constancia_number', 30)->nullable();
                $table->string('operation_number', 60)->nullable();
                $table->unsignedBigInteger('deposit_movement_id')->nullable();
                $table->string('status', 12)->default('pending'); // pending | deposited | received (overdue is computed)
                $table->timestamps();

                $table->unique(['source_type', 'source_id', 'side'], 'dr_source_side_unique');
                $table->index(['side', 'status', 'due_date'], 'dr_side_status_due_idx');
                $table->index('establishment_id', 'dr_establishment_idx');
                $table->index('user_id', 'dr_user_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('detraction_records');
    }
}
