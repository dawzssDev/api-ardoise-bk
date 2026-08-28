<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categoria_productos', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(1)->after('name');
            $table->index(['negocio_id', 'status']);
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(1)->after('image');
            $table->index(['negocio_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('categoria_productos', function (Blueprint $table) {
            $table->dropIndex(['negocio_id', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex(['negocio_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
