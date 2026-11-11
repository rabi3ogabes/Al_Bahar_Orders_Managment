<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 07 — Payments (SADAD, COD, pay at pickup), transactions, webhooks, refunds, reconciliation.
 * Financial history is never soft-deleted; transaction/webhook/collection rows are append-only by convention
 * and payment_transactions/payment_webhook_events are protected by triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payable_type', 48); // order | auction_deposit
            $table->uuid('payable_id');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 24);    // sadad | cod | pay_at_pickup
            $table->string('provider', 24);  // sadad | offline
            $table->string('status', 24)->default('pending');
            $table->char('currency', 3)->default('QAR');
            $table->decimal('amount', 12, 2);
            $table->decimal('captured_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('provider_reference', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
            $table->index(['method', 'status']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','processing','authorized','paid','failed','cancelled','expired','partially_refunded','refunded'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_money_check CHECK (amount >= 0 AND captured_amount >= 0 AND refunded_amount >= 0 AND refunded_amount <= captured_amount AND captured_amount <= amount)');
        DB::statement('CREATE UNIQUE INDEX payments_provider_reference_unique ON payments (provider, provider_reference) WHERE provider_reference IS NOT NULL');

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->string('type', 24); // initiate | status_check | callback | capture | refund | void | collection
            $table->string('gateway', 24);
            $table->string('transaction_reference', 128)->nullable();
            $table->string('status', 24);
            $table->decimal('amount', 12, 2)->nullable();
            $table->jsonb('request')->nullable();  // redacted
            $table->jsonb('response')->nullable(); // redacted
            $table->string('error', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX payment_transactions_reference_unique ON payment_transactions (gateway, transaction_reference, type) WHERE transaction_reference IS NOT NULL');
        DB::unprepared('CREATE TRIGGER payment_transactions_append_only BEFORE UPDATE OR DELETE ON payment_transactions FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()');

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 24);
            $table->string('provider_event_id', 191);
            $table->string('payload_hash', 64);
            $table->jsonb('payload'); // redacted
            $table->boolean('signature_valid');
            $table->string('status', 16)->default('received'); // received | processed | ignored | failed
            $table->uuid('payment_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('QAR');
            $table->string('reason_code', 48);
            $table->text('reason')->nullable();
            $table->string('method', 24); // original | manual_cash | manual_bank_transfer
            $table->string('status', 16)->default('requested'); // requested | approved | processing | processed | failed | rejected
            $table->string('provider_reference', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 24); // sadad | cod | pay_at_pickup
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft'); // draft | finalized
            $table->decimal('expected_total', 14, 2)->default(0);
            $table->decimal('actual_total', 14, 2)->default(0);
            $table->decimal('difference', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUuid('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('cash_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->uuid('order_fulfillment_id')->nullable();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('collected_by')->constrained('users')->restrictOnDelete();
            $table->string('context', 16); // cod | pay_at_pickup
            $table->string('tender', 16);  // cash | card_terminal
            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_collected', 12, 2);
            $table->string('status', 16); // collected | partial | failed
            $table->string('receipt_reference', 128)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampTz('collected_at');
            $table->foreignUuid('payment_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('handed_in_at')->nullable(); // cash handed in to branch cashier
            $table->foreignUuid('handed_in_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'collected_at']);
            $table->index(['collected_by', 'collected_at']);
        });
        DB::statement('ALTER TABLE cash_collections ADD CONSTRAINT cash_collections_money_check CHECK (amount_due >= 0 AND amount_collected >= 0)');

        Schema::create('payment_reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_collection_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('actual_amount', 12, 2)->nullable();
            $table->string('status', 16); // matched | mismatch | missing | unexpected
            $table->string('note', 500)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['payment_reconciliation_items', 'cash_collections', 'payment_reconciliations', 'refunds',
            'payment_webhook_events', 'payment_transactions', 'payments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
