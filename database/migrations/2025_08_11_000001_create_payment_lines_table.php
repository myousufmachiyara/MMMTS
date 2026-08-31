<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A single payment can now be split across multiple methods (cash,
     * cheque, online transfer) — each captured as its own line with its
     * own account and, for cheque, cheque number/date. The payment header
     * (payments table) no longer carries payment_method/account_id/voucher_id
     * directly since a payment can have several of each now.
     *
     * Written to be safely re-runnable: table creation is guarded so a
     * retry after a partial run doesn't hit "table already exists", and
     * every FK drop/column drop on `payments` is defensive since this
     * database doesn't have all FKs under Laravel's default names.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payment_lines')) {
            Schema::create('payment_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_id');
                $table->enum('method', ['cash', 'cheque', 'online_transfer']);
                $table->unsignedBigInteger('account_id'); // chart_of_accounts — the cash/bank account used
                $table->decimal('amount', 14, 2);
                $table->string('cheque_no', 50)->nullable();
                $table->date('cheque_date')->nullable();
                $table->string('reference')->nullable(); // transaction reference (online transfer)
                $table->unsignedBigInteger('voucher_id')->nullable(); // auto-posted Dr Cash/Bank / Cr Customer, one per line
                $table->timestamps();

                $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
                $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('set null');
            });
        }

        // Drop each FK on payments defensively — this database doesn't have
        // all FKs under Laravel's default constraint names, and dropForeign()
        // errors out (MySQL #1091) if asked to drop one that isn't there.
        foreach (['account_id', 'voucher_id'] as $column) {
            try {
                Schema::table('payments', function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            } catch (\Throwable $e) {
                // FK didn't exist on this database — nothing to drop, continue.
            }
        }

        // Drop each column individually and only if still present, so a
        // retry after a partial run doesn't hit "column doesn't exist".
        foreach (['payment_method', 'account_id', 'voucher_id'] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                Schema::table('payments', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'online_transfer'])->default('cash')->after('amount');
            $table->unsignedBigInteger('account_id')->nullable()->after('payment_method');
            $table->unsignedBigInteger('voucher_id')->nullable()->after('reference');

            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('set null');
        });

        Schema::dropIfExists('payment_lines');
    }
};