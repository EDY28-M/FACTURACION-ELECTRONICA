<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration creating `treasury_withholdings` (Treasury Phase 4).
 *
 * Links retention certificates (CRE, tipo 20) and perception certificates (CPE, tipo 40) to the
 * invoices/payments they affect. It never stores XML or SUNAT state: the certificates themselves are
 * issued by the existing Retention/Perception flows and are only referenced here.
 */
class TenantCreateTreasuryWithholdingsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_withholdings')) {
            Schema::create('treasury_withholdings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('kind', 24); // retention_received | retention_emitted | perception_emitted
                $table->string('certificate_type', 2); // 20 (CRE) | 40 (CPE)
                $table->string('series', 10)->nullable();
                $table->string('number', 20)->nullable();
                $table->date('date');
                $table->unsignedInteger('establishment_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();

                // The payment (purchase_payments / document_payments) or invoice this certificate affects.
                $table->string('source_type', 120);
                $table->unsignedInteger('source_id');
                // The parent obligation (Document or Purchase).
                $table->string('parent_type', 120)->nullable();
                $table->unsignedInteger('parent_id')->nullable();

                // Reference to the certificate issued through the existing SUNAT flows.
                $table->unsignedInteger('retention_id')->nullable();
                $table->unsignedInteger('perception_id')->nullable();

                $table->unsignedInteger('person_id')->nullable();
                $table->string('counterparty_document', 20)->nullable();
                $table->string('counterparty_name', 255)->nullable();

                $table->string('currency_type_id', 3)->default('PEN');
                $table->decimal('base_amount', 14, 2)->default(0);
                $table->decimal('rate', 8, 5)->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->decimal('amount_pen', 14, 2)->default(0);

                // Retained/perceived amounts are owed to SUNAT (Form. 626) until settled with a tax_payment movement.
                $table->boolean('payable_to_sunat')->default(false);
                $table->unsignedBigInteger('settled_movement_id')->nullable();

                $table->string('state', 10)->default('active'); // active | voided
                $table->dateTime('voided_at')->nullable();
                $table->unsignedInteger('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['kind', 'state', 'date'], 'tw_kind_state_date_idx');
                $table->index(['source_type', 'source_id'], 'tw_source_idx');
                $table->index(['parent_type', 'parent_id'], 'tw_parent_idx');
                $table->index('retention_id', 'tw_retention_idx');
                $table->index('perception_id', 'tw_perception_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('treasury_withholdings');
    }
}
