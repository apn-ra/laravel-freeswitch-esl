<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbx_connection_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('pbx_providers')
                ->nullOnDelete()
                ->comment('Optional: restrict profile to a specific provider');
            $table->string('name')->unique();
            $table->json('retry_policy_json')->nullable()->comment('Retry/reconnect policy overrides');
            $table->json('drain_policy_json')->nullable()->comment('Graceful drain policy overrides');
            $table->json('subscription_profile_json')->nullable()->comment('ESL event subscription configuration');
            $table->json('replay_policy_json')->nullable()->comment('Replay capture settings for this profile');
            $table->json('normalization_profile_json')->nullable()->comment('Event normalization settings');
            $table->json('worker_profile_json')->nullable()->comment('Worker operational settings');
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_connection_profiles');
    }
};
