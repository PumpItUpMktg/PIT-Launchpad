<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->string('framing_model')->default('problem_solution');
            $table->json('tone_axes')->nullable();
            $table->string('reading_level')->nullable();
            $table->string('jargon_policy')->nullable();
            $table->json('format_conventions')->nullable();
            $table->json('language_rules')->nullable();
            $table->json('audience')->nullable();
            $table->string('cta_voice')->nullable();
            $table->json('persona')->nullable();
            $table->json('calibration_refs')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'version']);
        });

        // At most one active voice profile per site.
        DB::statement("CREATE UNIQUE INDEX voice_profiles_active_per_site ON voice_profiles (site_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profiles');
    }
};
