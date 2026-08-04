<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por si `ordenes` / `orden_detalles` ya existían sin tracking de staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ordenes') && ! Schema::hasColumn('ordenes', 'created_by_staff_id')) {
            Schema::table('ordenes', function (Blueprint $table) {
                $table->foreignId('created_by_staff_id')->nullable()->after('status')->constrained('staff')->nullOnDelete();
                $table->foreignId('advanced_by_staff_id')->nullable()->after('created_by_staff_id')->constrained('staff')->nullOnDelete();
                $table->foreignId('finished_by_staff_id')->nullable()->after('advanced_by_staff_id')->constrained('staff')->nullOnDelete();
                $table->timestamp('advanced_at')->nullable()->after('finished_by_staff_id');
                $table->timestamp('finished_at')->nullable()->after('advanced_at');
            });
        }

        if (Schema::hasTable('orden_detalles') && ! Schema::hasColumn('orden_detalles', 'advanced_by_staff_id')) {
            Schema::table('orden_detalles', function (Blueprint $table) {
                $table->foreignId('advanced_by_staff_id')->nullable()->after('status')->constrained('staff')->nullOnDelete();
                $table->foreignId('finished_by_staff_id')->nullable()->after('advanced_by_staff_id')->constrained('staff')->nullOnDelete();
                $table->timestamp('advanced_at')->nullable()->after('finished_by_staff_id');
                $table->timestamp('finished_at')->nullable()->after('advanced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ordenes') && Schema::hasColumn('ordenes', 'created_by_staff_id')) {
            Schema::table('ordenes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by_staff_id');
                $table->dropConstrainedForeignId('advanced_by_staff_id');
                $table->dropConstrainedForeignId('finished_by_staff_id');
                $table->dropColumn(['advanced_at', 'finished_at']);
            });
        }

        if (Schema::hasTable('orden_detalles') && Schema::hasColumn('orden_detalles', 'advanced_by_staff_id')) {
            Schema::table('orden_detalles', function (Blueprint $table) {
                $table->dropConstrainedForeignId('advanced_by_staff_id');
                $table->dropConstrainedForeignId('finished_by_staff_id');
                $table->dropColumn(['advanced_at', 'finished_at']);
            });
        }
    }
};
