<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            // Stored via Laravel's encrypted cast (encrypted:array). No plaintext at rest.
            $table->text('credentials')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
