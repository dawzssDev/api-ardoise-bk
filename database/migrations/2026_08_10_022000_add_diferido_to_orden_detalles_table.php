<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->boolean('diferido')->default(false)->after('precio_lista');
            $table->foreignId('empleado_id')
                ->nullable()
                ->after('diferido')
                ->constrained('empleados')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empleado_id');
            $table->dropColumn('diferido');
        });
    }
};
