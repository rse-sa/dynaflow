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
        Schema::table('dynaflow_steps', function (Blueprint $table) {
            // Step type: determines execution behavior
            // 'approval' (default) - stateful, requires human decision
            // 'form', 'review' - other stateful types
            // 'action', 'notification', 'http', 'script', 'decision', 'timer' - stateless auto-executing
            // 'parallel', 'join' - flow control
            $table->string('type')->default('approval')->after('key');

            // Action handler key for auto-executing steps (e.g., 'email', 'http', 'script')
            $table->string('action_handler')->nullable()->after('type');

            // JSON configuration for the action handler
            $table->json('action_config')->nullable()->after('action_handler');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dynaflow_steps', function (Blueprint $table) {
            $table->dropColumn(['type', 'action_handler', 'action_config']);
        });
    }
};
