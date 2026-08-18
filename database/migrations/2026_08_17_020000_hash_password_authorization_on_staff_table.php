<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff', 'password_authorization')) {
            return;
        }

        $type = Schema::getColumnType('staff', 'password_authorization');
        if (! in_array($type, ['string', 'varchar', 'text', 'char'], true)) {
            Schema::table('staff', function (Blueprint $table) {
                $table->string('password_authorization', 255)->nullable()->change();
            });
        }

        DB::table('staff')
            ->whereNotNull('password_authorization')
            ->orderBy('id')
            ->each(function (object $row): void {
                $value = (string) $row->password_authorization;
                if ($value === '' || Hash::isHashed($value)) {
                    return;
                }

                DB::table('staff')->where('id', $row->id)->update([
                    'password_authorization' => Hash::make($value),
                ]);
            });
    }

    public function down(): void
    {
        // No se puede recuperar el PIN en texto plano una vez hasheado.
    }
};
