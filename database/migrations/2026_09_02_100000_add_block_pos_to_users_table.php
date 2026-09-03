<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1 = POS/sistema bloqueado (solo Mi Negocio / facturación); 0 = acceso completo
            $table->unsignedInteger('block_POS')->default(0)->after('limit_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('block_POS');
        });
    }
};
