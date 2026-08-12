<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cuentas_por_cobrar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('orden_detalle_id')->constrained('orden_detalles')->cascadeOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->constrained('tb_turnos_cajas')->nullOnDelete();
            $table->string('concepto');
            $table->decimal('monto', 10, 2);
            $table->enum('status', ['pendiente', 'pagado'])->default('pendiente');
            $table->dateTime('fecha_generado');
            $table->dateTime('fecha_pagado')->nullable();
            $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['negocio_id', 'status']);
            $table->index(['negocio_id', 'empleado_id', 'status']);
            $table->index(['sucursal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cuentas_por_cobrar');
    }
};
