<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator audit trail for payout recovery (B1).
 *
 * Every operator action that moves a payout — a retry of a failed transfer, the
 * release of a payout parked for review with an explicit amount, or a lookup of an
 * ambiguous transfer — writes one immutable row here, in the same transaction as
 * the state change it describes. Rows are never updated or deleted by the
 * application; they exist so that "who moved this money, from what state, and
 * why" can be answered without reading logs.
 *
 * Deliberately narrow: this is not a general audit-log product. It holds no
 * provider payloads and no credentials — only our own references, states and the
 * operator-supplied amount/reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_recovery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // retry | release | lookup
            $table->string('action', 20);
            $table->string('previous_status', 20);
            // Null when the action changed nothing (rejected, or lookup still unresolved).
            $table->string('new_status', 20)->nullable();
            // Who or what performed it: 'artisan' for the operator commands.
            $table->string('source', 40);
            // The operator-supplied amount for a release; null otherwise.
            $table->decimal('amount', 12, 2)->nullable();
            // The state the payout was in when it was parked (its last_error), or the
            // operator's note — whatever explains why this action was taken.
            $table->text('reason')->nullable();
            // Machine-readable outcome: reset, dispatched, rejected, resolved, released, unresolved…
            $table->string('result', 255);
            $table->timestamp('created_at');

            $table->index(['payout_id', 'created_at'], 'payout_recovery_events_payout_created_index');
            $table->index(['school_id', 'created_at'], 'payout_recovery_events_school_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_recovery_events');
    }
};
