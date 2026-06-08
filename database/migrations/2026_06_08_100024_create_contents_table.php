<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('silo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('page');
            $table->string('page_type')->nullable();
            $table->string('intake_type')->nullable();
            $table->foreignUlid('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('target_keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->foreignUlid('wireframe_kit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('status')->default('candidate');
            $table->string('seo_profile')->nullable();
            $table->json('meta')->nullable();
            $table->string('schema_type')->nullable();
            $table->json('schema_payload')->nullable();
            $table->json('slot_payload')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedInteger('voice_profile_version')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
