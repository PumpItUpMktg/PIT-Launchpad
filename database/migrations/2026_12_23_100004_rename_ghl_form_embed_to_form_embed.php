<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genericize the lead-form embed: it holds ANY provider's embed snippet (a GoHighLevel form, a
 * Jotform, a plain HTML form, …), not just GHL — so drop the provider from the column name.
 * `conversion_configs.ghl_form_embed` → `form_embed`, matching the already-generic meta-blob key
 * and PageConfig.form_embed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->renameColumn('ghl_form_embed', 'form_embed');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->renameColumn('form_embed', 'ghl_form_embed');
        });
    }
};
