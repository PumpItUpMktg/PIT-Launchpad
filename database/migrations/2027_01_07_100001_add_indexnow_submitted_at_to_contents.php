<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a published page's URL is accepted by IndexNow (Bing / Yandex / Seznam / Naver), stamp the time —
 * so the live cards can show a "Submitted to Bing" pill (a submission acknowledgment, distinct from the
 * earned "In Google" signal). Nullable: null = never submitted / IndexNow disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->timestamp('indexnow_submitted_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('indexnow_submitted_at');
        });
    }
};
