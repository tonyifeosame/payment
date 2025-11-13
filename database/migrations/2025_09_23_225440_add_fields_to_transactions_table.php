<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'category_name')) {
                $table->string('category_name')->after('subcategory_id');
            }
            if (! Schema::hasColumn('transactions', 'subcategory_name')) {
                $table->string('subcategory_name')->after('category_name');
            }
            if (! Schema::hasColumn('transactions', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('status');
            }
            if (! Schema::hasColumn('transactions', 'admission_number')) {
                $table->string('admission_number')->after('subcategory_name');
            }
            if (! Schema::hasColumn('transactions', 'email')) {
                $table->string('email')->after('admission_number');
            }
            if (! Schema::hasColumn('transactions', 'name')) {
                $table->string('name')->nullable()->after('email');
            }
            if (! Schema::hasColumn('transactions', 'amount')) {
                $table->decimal('amount', 10, 2)->after('name');
            }
            if (! Schema::hasColumn('transactions', 'meta_data')) {
                $table->json('meta_data')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('transactions', 'status')) {
                $table->string('status')->default('pending')->after('meta_data');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('transactions', 'meta_data')) {
                $table->dropColumn('meta_data');
            }
            if (Schema::hasColumn('transactions', 'amount')) {
                $table->dropColumn('amount');
            }
            if (Schema::hasColumn('transactions', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('transactions', 'email')) {
                $table->dropColumn('email');
            }
            if (Schema::hasColumn('transactions', 'admission_number')) {
                $table->dropColumn('admission_number');
            }
            if (Schema::hasColumn('transactions', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('transactions', 'subcategory_name')) {
                $table->dropColumn('subcategory_name');
            }
            if (Schema::hasColumn('transactions', 'category_name')) {
                $table->dropColumn('category_name');
            }
        });
    }
};
