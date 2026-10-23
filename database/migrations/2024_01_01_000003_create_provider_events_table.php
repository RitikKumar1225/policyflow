<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->string('event_type'); // POLICY_ISSUED, POLICY_FAILED
            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable();
            $table->text('payload')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
