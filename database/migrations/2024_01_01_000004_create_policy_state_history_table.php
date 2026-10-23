<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('trigger')->nullable(); // What caused the transition
            $table->text('metadata')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
