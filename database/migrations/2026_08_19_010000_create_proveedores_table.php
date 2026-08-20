<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('legal_name', 180)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['negocio_id', 'name']);
            $table->index(['negocio_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
