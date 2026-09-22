<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable record of the school-admin actions that change identity, money or
 * are destructive (M7).
 *
 * Modelled on `payout_recovery_events`, which does the same job for operator
 * payout actions: one immutable row per action, written in the same transaction
 * as the change it describes, never updated or deleted by the application.
 *
 * What it can and cannot answer. A school has exactly ONE credential
 * (`schools.admin_password`), so no record here can name a person — `actor`
 * names a ROLE and `actor_session` is a hash that distinguishes one signed-in
 * session from another. Together with `created_at`, `school_id`, `action` and
 * `changes`, that answers "when, for which school, what changed, and was it all
 * one sitting" — but never "which member of staff". Naming a person would need
 * separate admin identities, which is deliberately out of scope.
 *
 * Deliberately narrow, like the table it copies: no passwords or hashes, no
 * Paystack secrets or recipient codes, no full account numbers (last four
 * only), and no student personal data in this first scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // The role that acted: school_admin | artisan | system. Never a person.
            $table->string('actor', 40);

            // SHA-256 of the session id — never the id itself, which is a live
            // credential. Null for non-session actors. Stable within one session,
            // different across sessions, and not reversible to the session id.
            $table->string('actor_session', 64)->nullable();

            // Dotted action name: settings.profile_changed, fee.deleted, …
            $table->string('action', 60);

            // The row affected, when the action is about one: 'subcategory', 42.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // {"field": {"from": …, "to": …}} for the fields that actually changed,
            // already redacted by the recorder. Null when the action has no diff.
            $table->json('changes')->nullable();

            // Immutable: written once, never updated. No updated_at on purpose.
            $table->timestamp('created_at');

            $table->index(['school_id', 'created_at'], 'school_audit_events_school_created_index');
            $table->index(['school_id', 'action'], 'school_audit_events_school_action_index');
            $table->index(['subject_type', 'subject_id'], 'school_audit_events_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_audit_events');
    }
};
