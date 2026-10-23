<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination'); // DOMESTIC or INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code'); // BASIC, PLUS, PREMIUM
            $table->unsignedBigInteger('sum_insured');
            $table->decimal('premium', 12, 2)->nullable();
            $table->string('status')->default('RECEIVED');
            $table->string('policy_number')->nullable()->unique();
            $table->string('provider_reference')->nullable();
            $table->text('last_provider_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id'], 'unique_agent_client_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
