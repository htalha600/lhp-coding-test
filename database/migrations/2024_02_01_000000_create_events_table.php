<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Event date/time, stored as a unix timestamp (UTC).
            $table->unsignedBigInteger('event_time')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->longText('payload');
            $table->timestamps();

            $table->index('event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
