<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'reference')) {
                $table->string('reference')->nullable()->after('id');
            }
            if (! Schema::hasColumn('transactions', 'category_name')) {
                $table->string('category_name')->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('transactions', 'subcategory_name')) {
                $table->string('subcategory_name')->nullable()->after('subcategory_id');
            }
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'subcategory_name')) {
                $table->dropColumn('subcategory_name');
            }
            if (Schema::hasColumn('transactions', 'category_name')) {
                $table->dropColumn('category_name');
            }
            if (Schema::hasColumn('transactions', 'reference')) {
                $table->dropColumn('reference');
            }
        });
    }
};
