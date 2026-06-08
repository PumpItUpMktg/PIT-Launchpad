<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('photo');
            $table->string('source')->default('uploaded');
            $table->json('service_tags')->nullable();
            $table->json('market_tags')->nullable();
            $table->boolean('rights_ok')->default(false);
            $table->string('r2_key')->nullable();
            $table->string('alt_text')->nullable();
            $table->json('dimensions')->nullable();
            $table->foreignUlid('render_job_id')->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
