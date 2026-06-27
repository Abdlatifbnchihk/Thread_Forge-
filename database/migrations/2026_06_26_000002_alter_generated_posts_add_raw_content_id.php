<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_posts', function (Blueprint $table) {
            $table->dropColumn('raw_content');
            $table->foreignId('raw_content_id')->nullable()->after('campaign_blueprint_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_posts', function (Blueprint $table) {
            $table->dropForeign(['raw_content_id']);
            $table->dropColumn('raw_content_id');
            $table->text('raw_content');
        });
    }
};
