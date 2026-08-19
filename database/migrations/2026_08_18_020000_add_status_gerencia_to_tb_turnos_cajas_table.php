<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->string('status_gerencia', 20)
                ->default('abierto')
                ->after('status_administrador');
            $table->index(
                ['negocio_id', 'sucursal_id', 'status_gerencia'],
                'tb_turnos_cajas_negocio_sucursal_status_gerencia_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropIndex('tb_turnos_cajas_negocio_sucursal_status_gerencia_index');
            $table->dropColumn('status_gerencia');
        });
    }
};
