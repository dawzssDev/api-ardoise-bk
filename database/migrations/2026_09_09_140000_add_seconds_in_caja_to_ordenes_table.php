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
            if (! Schema::hasColumn('ordenes', 'seconds_in_caja')) {
                $table->unsignedInteger('seconds_in_caja')->nullable()->after('seconds_in_preparacion');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) {
            if (Schema::hasColumn('ordenes', 'seconds_in_caja')) {
                $table->dropColumn('seconds_in_caja');
            }
        });
    }
};
