<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_accounts', function (Blueprint $t) {
            if (! Schema::hasColumn('workforce_accounts', 'recruitment_notice_version')) {
                $t->string('recruitment_notice_version', 80)->nullable();
            }
            if (! Schema::hasColumn('workforce_accounts', 'recruitment_notice_accepted_at')) {
                $t->timestamp('recruitment_notice_accepted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Retain recruitment consent evidence during code rollback.');
    }
};
