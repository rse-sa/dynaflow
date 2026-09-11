<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dynaflow_steps', function (Blueprint $table) {
            $table->string('completion_policy', 20)->default('any')->after('type');
            $table->unsignedSmallInteger('min_approvals')->nullable()->after('completion_policy');
        });
    }

    public function down(): void
    {
        Schema::table('dynaflow_steps', function (Blueprint $table) {
            $table->dropColumn(['completion_policy', 'min_approvals']);
        });
    }
};
