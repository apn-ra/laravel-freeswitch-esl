<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('worker_name')->index()->comment('Logical worker name (maps to artisan freeswitch:worker --worker=)');
            $table->string('assignment_mode')
                ->comment('node|cluster|tag|provider|all-active');
            $table->foreignId('pbx_node_id')
                ->nullable()
                ->constrained('pbx_nodes')
                ->nullOnDelete()
                ->comment('Set when assignment_mode = node');
            $table->string('provider_code')->nullable()
                ->comment('Set when assignment_mode = provider');
            $table->string('cluster')->nullable()
                ->comment('Set when assignment_mode = cluster');
            $table->string('tag')->nullable()
                ->comment('Set when assignment_mode = tag');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['worker_name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_assignments');
    }
};
