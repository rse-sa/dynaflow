<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynaflow_parallel_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynaflow_instance_id')
                ->constrained('dynaflow_instances')
                ->cascadeOnDelete();
            $table->string('group_id')->index();
            $table->string('branch_key');
            $table->foreignId('step_id')
                ->constrained('dynaflow_steps')
                ->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // Index for efficient group lookups
            $table->index(['dynaflow_instance_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynaflow_parallel_executions');
    }
};
