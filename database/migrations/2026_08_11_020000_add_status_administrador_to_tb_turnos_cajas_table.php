<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->string('status_administrador', 20)
                ->default('abierto')
                ->after('status');
            $table->index(['negocio_id', 'sucursal_id', 'status_administrador'], 'tb_turnos_cajas_negocio_sucursal_status_admin_index');
        });

        // Turnos ya cerrados por completo: alinear status_administrador.
        DB::table('tb_turnos_cajas')
            ->where('status', 'cerrado')
            ->update(['status_administrador' => 'cerrado']);
    }

    public function down(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropIndex('tb_turnos_cajas_negocio_sucursal_status_admin_index');
            $table->dropColumn('status_administrador');
        });
    }
};
