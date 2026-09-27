<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remember me" credentials for school admins: one row per remembered browser.
 *
 * The browser holds `selector:verifier` in the school_remember cookie. The
 * selector is a random public handle used to find the row; the verifier is a
 * random secret stored here only as a SHA-256 hash and compared in constant
 * time. Neither the school id nor anything derived from the password is in the
 * cookie. See App\Support\SchoolRemember.
 *
 * A row stops working when it expires, when it is revoked (logout on that
 * browser, rotation after use, a password change or reset), or when the
 * school's password no longer matches `password_fingerprint` — the same
 * fingerprint SchoolSession uses to revoke sessions (H6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // Random public lookup handle (32 hex chars). Not a secret on its own.
            $table->string('selector', 32)->unique();

            // SHA-256 of the verifier. The verifier itself is never stored.
            $table->string('token_hash', 64);

            // SchoolSession::fingerprint() of the password at issue time.
            $table->string('password_fingerprint', 64);

            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'revoked_at'], 'school_remember_tokens_school_revoked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_remember_tokens');
    }
};
