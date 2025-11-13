<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'admission_number')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('admission_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'admission_number')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('admission_number')->nullable();
            });
        }
    }
};
