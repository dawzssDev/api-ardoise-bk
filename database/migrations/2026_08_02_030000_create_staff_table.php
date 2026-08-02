<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usuarios operativos (staff). Separados de `users` (dueños / maestros).
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('username');
            $table->string('password');
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['negocio_id', 'username']);
            $table->unique(['empleado_id']);
            $table->index(['negocio_id', 'status']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
