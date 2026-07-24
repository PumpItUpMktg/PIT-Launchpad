<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_states', function (Blueprint $table): void {
            // The reason the last structure build failed — surfaced to the operator on "Start over"
            // instead of a bare "check the logs". Null when the last build succeeded.
            $table->string('structure_error', 500)->nullable()->after('structure_status');
        });
    }

    public function down(): void
    {
        Schema::table('setup_states', function (Blueprint $table): void {
            $table->dropColumn('structure_error');
        });
    }
};
