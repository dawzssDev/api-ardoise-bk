<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_turnos_cajas', function (Blueprint $table) {
            $table->id();
            // Cajera (staff). Nullable si el maestro abre el turno.
            $table->foreignId('id_user')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->decimal('fondo_inicial', 12, 2)->default(0);
            $table->decimal('total_ventas_efectivo', 12, 2)->default(0);
            $table->decimal('total_ventas_tarjeta', 12, 2)->default(0);
            $table->decimal('total_ventas_transferencia', 12, 2)->default(0);
            $table->decimal('total_ventas', 12, 2)->default(0);
            $table->decimal('total_pagos_proveedores', 12, 2)->default(0);
            $table->decimal('total_gastos_operativos', 12, 2)->default(0);
            $table->decimal('efectivo_esperado', 12, 2)->default(0);
            $table->decimal('efectivo_real', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->string('status', 20)->default('abierto'); // abierto|cerrado
            $table->timestamp('fecha_apertura')->useCurrent();
            $table->timestamp('fecha_cierre')->nullable();
            $table->text('observaciones_cierre')->nullable();
            $table->timestamps();

            $table->index(['negocio_id', 'sucursal_id', 'status']);
            $table->index(['id_user', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('tb_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('tb_turnos_cajas')->restrictOnDelete();
            $table->foreignId('id_user')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $table->unsignedInteger('order_number');
            $table->string('payment_type', 30);
            $table->decimal('total', 12, 2);
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->timestamp('fecha_venta');
            $table->timestamps();

            $table->index(['turno_caja_id', 'payment_type']);
            $table->index(['negocio_id', 'sucursal_id', 'fecha_venta']);
            $table->index('orden_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ventas');
        Schema::dropIfExists('tb_turnos_cajas');
    }
};
