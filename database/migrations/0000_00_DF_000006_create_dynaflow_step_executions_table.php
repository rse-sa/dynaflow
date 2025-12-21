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
        Schema::create('dynaflow_step_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynaflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dynaflow_step_id')->constrained()->cascadeOnDelete();
            $table->nullableUlidMorphs('executed_by');
            $table->string('decision');
            $table->text('note')->nullable();
            $table->integer('duration')->nullable()->comment('minutes');
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynaflow_step_executions');
    }
};
