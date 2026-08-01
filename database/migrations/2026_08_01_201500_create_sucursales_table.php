<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('type'); // sucursal | bodega
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('street')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->unsignedSmallInteger('opened_year')->nullable();
            $table->timestamps();

            $table->index(['negocio_id', 'type']);
            $table->index(['negocio_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
