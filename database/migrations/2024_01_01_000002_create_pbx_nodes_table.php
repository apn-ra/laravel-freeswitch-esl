<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbx_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')
                ->constrained('pbx_providers')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique()->comment('URL-safe stable identifier used in CLI and API');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(8021);
            $table->string('username')->default('');
            $table->string('password_secret_ref')
                ->comment('Opaque secret reference resolved by SecretResolverInterface — not the literal credential');
            $table->string('transport')->default('tcp')->comment('tcp|tls');
            $table->boolean('is_active')->default(true)->index();
            $table->string('region')->nullable()->index();
            $table->string('cluster')->nullable()->index();
            $table->json('tags_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->string('health_status')->default('unknown')
                ->comment('healthy|degraded|unhealthy|unknown');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'cluster']);
            $table->index(['is_active', 'region']);
            $table->index(['is_active', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_nodes');
    }
};
