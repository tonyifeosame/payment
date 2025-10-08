<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('paystack_recipient_code')->nullable()->after('bank_code');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->date('payout_date');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status')->default('pending'); // pending|success|failed
            $table->string('transfer_code')->nullable();
            $table->string('transfer_id')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('paystack_recipient_code');
        });
    }
};
