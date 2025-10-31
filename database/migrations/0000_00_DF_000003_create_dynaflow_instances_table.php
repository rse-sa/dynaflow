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
        Schema::create('dynaflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynaflow_id')->constrained()->cascadeOnDelete();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('status')->default('pending');
            $table->ulidMorphs('triggered_by');
            $table->foreignId('current_step_id')->nullable()->constrained('dynaflow_steps');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynaflow_instances');
    }
};
