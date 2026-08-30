<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tipos_venta', function (Blueprint $table) {
            // 0 = no requiere autorización, 1 = sí requiere autorización
            $table->unsignedTinyInteger('require_autori')->default(0)->after('requiere_empleado');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tipos_venta', function (Blueprint $table) {
            $table->dropColumn('require_autori');
        });
    }
};
