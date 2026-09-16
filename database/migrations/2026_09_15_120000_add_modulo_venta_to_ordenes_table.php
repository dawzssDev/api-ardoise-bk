<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'moduloVenta')) {
                $table->unsignedTinyInteger('moduloVenta')->nullable()->default(1)->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) {
            if (Schema::hasColumn('ordenes', 'moduloVenta')) {
                $table->dropColumn('moduloVenta');
            }
        });
    }
};
