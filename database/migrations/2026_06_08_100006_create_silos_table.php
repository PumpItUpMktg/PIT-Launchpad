<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silos', function (Blueprint $table) {
            // Declare the primary key as an explicit, early command so it exists
            // before the self-referential parent_silo_id foreign key is added
            // (Postgres rejects an FK to a not-yet-created unique constraint).
            $table->ulid('id');
            $table->primary('id');
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('service_pillar');
            $table->foreignUlid('parent_silo_id')->nullable()->constrained('silos')->nullOnDelete();
            // FK to contents is deferred (circular dependency); see Silo::pillarContent().
            $table->ulid('pillar_content_id')->nullable()->index();
            $table->json('rule_set')->nullable();
            $table->unsignedBigInteger('wp_category_id')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silos');
    }
};
