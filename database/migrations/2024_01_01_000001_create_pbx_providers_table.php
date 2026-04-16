<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbx_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Provider family code (e.g. freeswitch)');
            $table->string('name');
            $table->string('driver_class')->comment('FQN of the ProviderDriverInterface implementation');
            $table->boolean('is_active')->default(true)->index();
            $table->json('capabilities_json')->nullable()->comment('Provider capability flags');
            $table->json('settings_json')->nullable()->comment('Provider-level settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_providers');
    }
};
