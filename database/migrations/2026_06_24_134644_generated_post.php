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
        //
        Schema::create('generated_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_blueprint_id')->constrained()->cascadeOnDelete();
            $table->text('raw_content');

            // --- Structured Output fields from the AI (laravel/ai) ---
            $table->string('hook_proposed', 280)->nullable();
            $table->json('body_points')->nullable();              // ["point 1", "point 2"]
            $table->unsignedTinyInteger('technical_readability_score')->nullable(); // 0-100
            $table->json('suggested_hashtags')->nullable();       // ["#Laravel", "#PHP"]
            $table->text('tone_compliance_justification')->nullable();

            // --- Lifecycle ---
            $table->enum('status', ['pending', 'draft', 'posted', 'archived'])->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('generated_posts');
    }
};
