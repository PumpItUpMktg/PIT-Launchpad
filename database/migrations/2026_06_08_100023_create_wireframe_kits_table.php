<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wireframe_kits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Nullable: library-level kits (null site_id) are shared across sites.
            $table->foreignUlid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('slot_schema');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireframe_kits');
    }
};
