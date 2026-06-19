<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_attendees', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->string('name');
            $table->string('email');
            // 'interested' or 'attending'
            $table->string('status')->default('attending');
            // reminder bookkeeping so each reminder is sent at most once
            $table->timestamp('reminded_3d_at')->nullable();
            $table->timestamp('reminded_24h_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->unique(['event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendees');
    }
};
