<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Petty-cash payouts (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md): money a cashier pays out of the open drawer (supplies, a taxi, a small repair).
 *
 *  - A payout belongs to ONE shift and lowers that shift's expected cash. It is recorded while the shift is open and never moves to another shift.
 *  - It is never deleted: a mistake is VOIDED (who, when, why), so the Z-report can show the voided line and the audit trail stays whole.
 *  - `request_key` makes a double tap harmless: the same key answers with the payout that already exists instead of paying out twice.
 *  - `approved_by` names the second person above the approval limit (Settings > Cash). `receipt_path` is a photo on the private disk.
 * Nothing here names a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_shift_id')->constrained('cash_shifts')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('request_key', 64)->nullable()->unique();
            $table->string('reason_code', 24);                                       // supplies | transport | maintenance | cleaning | refreshments | other
            $table->string('note', 300)->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignUuid('paid_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipt_path')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestampsTz();

            $table->index(['cash_shift_id', 'created_at']);                          // the payouts of one shift, in order
            $table->index('branch_id');
            $table->index('paid_by');
            $table->index('approved_by');
            $table->index('voided_by');
        });
        DB::statement('ALTER TABLE cash_payouts ADD CONSTRAINT cash_payouts_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_payouts ADD CONSTRAINT cash_payouts_reason_check CHECK (reason_code IN ('supplies','transport','maintenance','cleaning','refreshments','other'))");
        DB::statement('ALTER TABLE cash_payouts ADD CONSTRAINT cash_payouts_void_check CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payouts');
    }
};
