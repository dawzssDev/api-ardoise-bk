<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();

            // Datos personales
            $table->string('first_name');
            $table->string('paternal_surname');
            $table->string('maternal_surname')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable(); // masculino | femenino | otro
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 15)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();

            // Información laboral
            $table->string('employee_number', 50);
            $table->string('supervisor_name')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('contract_type', 50)->nullable();
            $table->string('shift', 50)->nullable();
            $table->string('status', 30)->default('activo'); // activo | inactivo | baja
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('salary_frequency', 20)->nullable(); // diario | semanal | quincenal
            $table->string('image')->nullable();

            // Contacto de emergencia
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_relationship', 80)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['negocio_id', 'employee_number']);
            $table->index(['negocio_id', 'status']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
