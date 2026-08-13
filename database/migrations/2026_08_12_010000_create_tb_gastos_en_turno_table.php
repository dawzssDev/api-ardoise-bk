<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->decimal('total_retiros_efectivo', 12, 2)->default(0)->after('total_gastos_operativos');
        });

        Schema::create('tb_gastos_en_turno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('tb_turnos_cajas')->restrictOnDelete();
            $table->foreignId('id_user')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('tipo_gasto', 30);
            $table->string('descripcion', 500);
            $table->decimal('monto', 12, 2);
            $table->timestamp('fecha_registro')->useCurrent();
            $table->timestamps();

            $table->index(['turno_caja_id', 'tipo_gasto']);
            $table->index(['negocio_id', 'sucursal_id', 'fecha_registro']);
            $table->index('id_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_gastos_en_turno');

        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropColumn('total_retiros_efectivo');
        });
    }
};
