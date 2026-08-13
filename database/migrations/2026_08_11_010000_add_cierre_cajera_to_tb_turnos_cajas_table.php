<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->decimal('efectivo_real_cajera', 12, 2)
                ->nullable()
                ->after('efectivo_real');
            $table->timestamp('fecha_cierre_cajera')
                ->nullable()
                ->after('fecha_cierre');
        });
    }

    public function down(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropColumn(['efectivo_real_cajera', 'fecha_cierre_cajera']);
        });
    }
};
