<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A channel the operator set aside (another brand's listing on the same Google account, a Page not to post
 * to): it receives no share and sits under "Ignored" on the Shares page until restored. The sync keeps it —
 * a delete would only come back on the next read of the provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->timestamp('ignored_at')->nullable()->after('location_locked');
        });
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table): void {
            $table->dropColumn('ignored_at');
        });
    }
};
