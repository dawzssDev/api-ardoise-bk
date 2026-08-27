<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_turnos_cajas_cortes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('tb_turnos_cajas')->restrictOnDelete();
            $table->foreignId('id_user')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->decimal('total_ventas_efectivo', 12, 2)->default(0);
            $table->decimal('total_ventas_tarjeta', 12, 2)->default(0);
            $table->decimal('total_ventas_transferencia', 12, 2)->default(0);
            $table->decimal('total_ventas', 12, 2)->default(0);
            $table->decimal('total_pagos_proveedores', 12, 2)->default(0);
            $table->decimal('total_gastos_operativos', 12, 2)->default(0);
            $table->decimal('total_retiros_efectivo', 12, 2)->default(0);
            $table->decimal('total_depositos_efectivo', 12, 2)->default(0);
            $table->decimal('efectivo_real_cajera', 12, 2)->default(0);
            $table->unsignedTinyInteger('tipo_corte');
            $table->timestamp('fecha_cierre_cajera')->nullable();
            $table->string('observaciones_cierre', 2000)->nullable();
            $table->timestamps();

            $table->index(['turno_caja_id', 'tipo_corte']);
            $table->index(['negocio_id', 'sucursal_id', 'fecha_cierre_cajera']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_turnos_cajas_cortes');
    }
};
