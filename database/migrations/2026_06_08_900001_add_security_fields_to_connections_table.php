<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // Every credential that transited the pilot is treated as exposed
            // until explicitly rotated, so new rows default to compromised.
            $table->boolean('compromised')->default(true);
            $table->string('compromised_reason')->nullable();
            $table->timestamp('exposed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['compromised', 'compromised_reason', 'exposed_at']);
        });
    }
};
