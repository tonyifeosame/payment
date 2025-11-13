<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->change();
            }
            if (Schema::hasColumn('transactions', 'subcategory_id')) {
                $table->unsignedBigInteger('subcategory_id')->nullable()->change();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category_id')) {
                $table->foreign('category_id')
                    ->references('id')->on('categories')
                    ->onDelete('set null');
            }
            if (Schema::hasColumn('transactions', 'subcategory_id')) {
                $table->foreign('subcategory_id')
                    ->references('id')->on('subcategories')
                    ->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('transactions', 'subcategory_id')) {
                $table->unsignedBigInteger('subcategory_id')->nullable(false)->change();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category_id')) {
                $table->foreign('category_id')
                    ->references('id')->on('categories')
                    ->onDelete('cascade');
            }
            if (Schema::hasColumn('transactions', 'subcategory_id')) {
                $table->foreign('subcategory_id')
                    ->references('id')->on('subcategories')
                    ->onDelete('cascade');
            }
        });
    }
};
