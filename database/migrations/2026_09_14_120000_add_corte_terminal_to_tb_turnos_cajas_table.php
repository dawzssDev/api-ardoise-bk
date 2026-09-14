<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->decimal('corte_terminal', 12, 2)->nullable()->after('observaciones_cierre');
        });
    }

    public function down(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropColumn('corte_terminal');
        });
    }
};
