<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add school_id columns (nullable for now to avoid DBAL requirement on change())
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
        });

        // Create a default school and backfill existing rows
        $exists = DB::table('schools')->where('slug', 'default-school')->exists();
        if (!$exists) {
            $defaultId = DB::table('schools')->insertGetId([
                'name' => 'Default School',
                'slug' => 'default-school',
                'email' => null,
                'account_number' => null,
                'bank' => null,
                'address' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('categories')->whereNull('school_id')->update(['school_id' => $defaultId]);
            DB::table('subcategories')->whereNull('school_id')->update(['school_id' => $defaultId]);
            DB::table('transactions')->whereNull('school_id')->update(['school_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
