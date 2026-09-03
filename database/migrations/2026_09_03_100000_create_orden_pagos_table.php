<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->string('payment_type', 30);
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index(['orden_id', 'payment_type'], 'orden_pagos_orden_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_pagos');
    }
};
