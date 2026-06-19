<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client sign-off on the proposed page plan (auto-arrange increment 5). The §7c client
 * portal renders the arranged inventory + lead-upside read-only; the owner approves it,
 * stamping who/when on their blueprint. A recorded sign-off — distinct from the operator's
 * Finalize hard gate (`confirmed_at`), which still owns what gets built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->timestamp('client_approved_at')->nullable()->after('confirmed_at');
            $table->ulid('client_approved_by')->nullable()->after('client_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->dropColumn(['client_approved_at', 'client_approved_by']);
        });
    }
};
