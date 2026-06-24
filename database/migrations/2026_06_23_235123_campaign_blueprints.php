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
        Schema::create('campaign_blueprints', function(Blueprint $table){
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('target_audience')->default('tech community');
            $table->string('tone')->default('professional but casual');
            $table->unsignedSmallInteger('max_characters')->default(280);
            $table->unsignedTinyInteger('max_hashtags')->default(1);
            $table->json('forbidden_words')->nullable();  // ["bro", "synergy", ...]
            $table->text('style_notes')->nullable();       // free-text extra instructions
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('campaign_blueprints');
    }
};
