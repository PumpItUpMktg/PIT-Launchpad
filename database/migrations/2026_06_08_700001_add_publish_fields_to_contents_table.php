<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Operator-edit protection: a locked page, or one the plugin reports
            // as edited directly in WP, is never overwritten by a re-publish.
            $table->boolean('locked')->default(false);
            $table->boolean('locally_edited')->default(false);
            // Last surfaced publish failure (push error), for the operator.
            $table->text('last_publish_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['locked', 'locally_edited', 'last_publish_error']);
        });
    }
};
