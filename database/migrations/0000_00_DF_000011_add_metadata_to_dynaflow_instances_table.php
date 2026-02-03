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
        Schema::table('dynaflows', function (Blueprint $table) {
            $table->json('data')->nullable()->after('ignored_fields');
            $table->json('metadata')->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dynaflows', function (Blueprint $table) {
            $table->dropColumn(['data', 'metadata']);
        });
    }
};
