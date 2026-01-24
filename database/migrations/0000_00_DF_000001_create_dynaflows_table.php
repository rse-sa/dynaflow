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
        Schema::create('dynaflows', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('topic');
            $table->string('action');
            $table->json('description')->nullable();
            $table->boolean('active')->default(true);

            $table->json('monitored_fields')->nullable();
            $table->json('ignored_fields')->nullable();

            $table->foreignId('overridden_by')->nullable()->constrained('dynaflows')->nullOnDelete();
            $table->timestamps();

            $table->index(['topic', 'action', 'active']);
            # $table->unique(['topic', 'action', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynaflows');
    }
};
