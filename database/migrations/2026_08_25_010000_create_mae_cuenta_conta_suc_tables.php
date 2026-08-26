<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mae_cuenta_conta_suc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('tipo_cuenta', 20);
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->restrictOnDelete();
            $table->string('titulo_cuenta', 150);
            $table->string('descripcion_cuenta', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('deleted')->default(0);
            $table->timestamps();

            $table->index(['negocio_id', 'tipo_cuenta', 'deleted']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'status', 'deleted']);
        });

        Schema::create('mae_cuenta_conta_suc_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('mae_cuenta_conta_suc_id')
                ->constrained('mae_cuenta_conta_suc')
                ->restrictOnDelete();
            $table->string('tipo_movimiento', 20);
            $table->string('descripcion_movimiento', 500);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('deleted')->default(0);
            $table->timestamps();

            $table->index(['negocio_id', 'mae_cuenta_conta_suc_id', 'deleted'], 'mae_cta_det_negocio_cuenta_deleted_index');
            $table->index(['mae_cuenta_conta_suc_id', 'tipo_movimiento'], 'mae_cta_det_cuenta_tipo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mae_cuenta_conta_suc_detalle');
        Schema::dropIfExists('mae_cuenta_conta_suc');
    }
};
