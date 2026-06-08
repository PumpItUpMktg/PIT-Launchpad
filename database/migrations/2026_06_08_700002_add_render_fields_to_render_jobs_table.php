<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            // One render job per §6b image spec (per content slot). Carries the
            // spec plus the minted result so the publish payload's images map is
            // assembled straight from the content's render jobs.
            $table->string('slot')->nullable();
            $table->string('seo_filename')->nullable();
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->string('caption')->nullable();
            // A required image that ends render_failed blocks the page's publish.
            $table->boolean('required')->default(true);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->dropColumn(['slot', 'seo_filename', 'alt', 'title', 'caption', 'required', 'attempts', 'width', 'height']);
        });
    }
};
