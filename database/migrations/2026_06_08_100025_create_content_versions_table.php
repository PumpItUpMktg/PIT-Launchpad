<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload_snapshot');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['content_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
