<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            // NumeroOrden: correlativo por negocio (se formatea a 6 dígitos en API)
            $table->unsignedInteger('order_number');
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('customer_name', 255);
            $table->string('payment_type', 30); // credito|transferencia|efectivo
            $table->decimal('total', 10, 2)->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            // Staff operativo (POS / cocina)
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('advanced_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('finished_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('advanced_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Correlativo independiente por sucursal (#000001 en cada una)
            $table->unique(['sucursal_id', 'order_number']);
            $table->index(['negocio_id', 'status']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'created_at']);
        });

        Schema::create('orden_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->string('product_name', 255);
            $table->decimal('quantity', 10, 3);
            $table->decimal('price', 10, 2);
            $table->json('extras')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            // Staff cocina: quién avanzó y quién finalizó el producto
            $table->foreignId('advanced_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('finished_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('advanced_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['orden_id', 'status']);
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_detalles');
        Schema::dropIfExists('ordenes');
    }
};
