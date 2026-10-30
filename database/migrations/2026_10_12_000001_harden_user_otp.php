<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing 4-digit codes never expired; drop them so only new hashed, expiring codes are accepted.
        DB::table('users')->update(['otp' => null]);
        Schema::table('users', function (Blueprint $table) {
            $table->string('otp', 255)->nullable()->change();
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
        });
    }

    public function down(): void
    {
        DB::table('users')->update(['otp' => null]);
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['otp_expires_at', 'otp_attempts']);
            $table->integer('otp')->nullable()->change();
        });
    }
};
