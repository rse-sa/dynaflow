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
        if (! Schema::hasColumn('dynaflows', 'data')) {
            Schema::table('dynaflows', function (Blueprint $table) {
                $table->json('data')->nullable()->after('ignored_fields');
            });
        }

        if (! Schema::hasColumn('dynaflows', 'metadata')) {
            Schema::table('dynaflows', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('data');
            });
        }

        if (! Schema::hasColumn('dynaflow_instances', 'metadata')) {
            Schema::table('dynaflow_instances', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('cancelled_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('dynaflows', 'data')) {
            Schema::table('dynaflows', function (Blueprint $table) {
                $table->dropColumn('data');
            });
        }

        if (Schema::hasColumn('dynaflows', 'metadata')) {
            Schema::table('dynaflows', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }

        if (Schema::hasColumn('dynaflow_instances', 'metadata')) {
            Schema::table('dynaflow_instances', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
