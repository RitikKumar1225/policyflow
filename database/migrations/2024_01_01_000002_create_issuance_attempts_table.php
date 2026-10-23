<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->unsignedInteger('attempt_number');
            $table->string('status'); // SUBMITTED, SUCCESS, FAILED, TIMEOUT
            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
