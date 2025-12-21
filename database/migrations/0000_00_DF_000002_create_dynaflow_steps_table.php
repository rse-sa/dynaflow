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
        Schema::create('dynaflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynaflow_id')->constrained()->cascadeOnDelete();
            $table->string('key')->nullable()->index();
            $table->json('name');
            $table->json('description')->nullable();
            $table->integer('order');
            $table->boolean('is_final')->default(false);
            $table->boolean('auto_close')->default(false);
            $table->string('workflow_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['dynaflow_id', 'order']);
            $table->unique(['dynaflow_id', 'key']);
        });

        Schema::create('dynaflow_step_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_step_id')->constrained('dynaflow_steps')->cascadeOnDelete();
            $table->foreignId('to_step_id')->constrained('dynaflow_steps')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['from_step_id', 'to_step_id']);
        });

        Schema::create('dynaflow_step_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynaflow_step_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('assignable');
            $table->timestamps();

            $table->unique(['dynaflow_step_id', 'assignable_type', 'assignable_id'], 'step_assignable_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynaflow_step_assignees');
        Schema::dropIfExists('dynaflow_step_transitions');
        Schema::dropIfExists('dynaflow_steps');
    }
};
