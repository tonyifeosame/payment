<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a school shows parents: a phone number, a logo and a receipt footer.
     *
     * logo_path is a path on the `local` (private) disk, served through a route that
     * reads it back, so no storage symlink is needed and the PDF renderer can embed
     * the same file from disk.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('logo_path')->nullable()->after('address');
            $table->text('receipt_footer')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['phone', 'logo_path', 'receipt_footer']);
        });
    }
};
