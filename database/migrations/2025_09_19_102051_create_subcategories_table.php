<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
      Schema::create('subcategories', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('category_id');   // ← this is missing in your case
    $table->string('name');
    $table->decimal('price', 10, 2)->nullable();
    $table->timestamps();

    $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
