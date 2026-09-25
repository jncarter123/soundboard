<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt any app secrets stored as plaintext by earlier versions. The
     * column is widened first since an encrypted payload exceeds 255 chars.
     */
    public function up(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->text('secret')->change();
        });

        DB::table('reverb_apps')->select('id', 'secret')->orderBy('id')->each(function ($row) {
            try {
                Crypt::decryptString($row->secret);

                return;
            } catch (DecryptException) {
                // Plaintext — encrypt it below.
            }

            DB::table('reverb_apps')
                ->where('id', $row->id)
                ->update(['secret' => Crypt::encryptString($row->secret)]);
        });
    }

    public function down(): void
    {
        DB::table('reverb_apps')->select('id', 'secret')->orderBy('id')->each(function ($row) {
            DB::table('reverb_apps')
                ->where('id', $row->id)
                ->update(['secret' => Crypt::decryptString($row->secret)]);
        });
    }
};
