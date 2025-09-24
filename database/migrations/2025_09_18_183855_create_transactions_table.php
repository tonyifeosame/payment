<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('admission_number');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('subcategory_id');
            $table->string('category_name')->nullable();     // ✅ Correctly placed
            $table->string('subcategory_name')->nullable();  // ✅ Correctly placed
            $table->json('meta_data')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('transactions');
    }
};
