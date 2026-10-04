<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename app brand from "Campus Logbook Management" to "Logbook".
     * Hanya meng-update baris yang masih memakai nama lama (default lama).
     */
    private const OLD_NAME = 'Campus Logbook Management';
    private const NEW_NAME = 'Logbook';

    public function up(): void
    {
        // Update app_name yang masih memakai nama lama.
        DB::table('institutions')
            ->where('app_name', self::OLD_NAME)
            ->update(['app_name' => self::NEW_NAME]);

        // Ganti substring nama lama pada teks lain yang memuatnya.
        foreach (['footer_note', 'mail_from_name'] as $column) {
            DB::table('institutions')
                ->where($column, 'like', '%' . self::OLD_NAME . '%')
                ->update([$column => DB::raw("REPLACE($column, '" . self::OLD_NAME . "', '" . self::NEW_NAME . "')")]);
        }
    }

    public function down(): void
    {
        DB::table('institutions')
            ->where('app_name', self::NEW_NAME)
            ->update(['app_name' => self::OLD_NAME]);

        foreach (['footer_note', 'mail_from_name'] as $column) {
            DB::table('institutions')
                ->where($column, 'like', '%' . self::NEW_NAME . '%')
                ->update([$column => DB::raw("REPLACE($column, '" . self::NEW_NAME . "', '" . self::OLD_NAME . "')")]);
        }
    }
};
