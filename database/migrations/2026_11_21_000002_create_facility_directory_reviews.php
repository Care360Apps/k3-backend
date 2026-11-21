<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('facility_directory_reviews')) return;
        Schema::create('facility_directory_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_directory_id')->constrained('facility_directory');
            $t->unsignedBigInteger('reviewer_id');
            $t->string('previous_review_status', 24);
            $t->string('new_review_status', 24);
            $t->string('previous_listing_status', 24);
            $t->string('new_listing_status', 24);
            $t->text('evidence_note');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['facility_directory_id', 'created_at'], 'facility_review_history');
        });
    }
    public function down(): void { Schema::dropIfExists('facility_directory_reviews'); }
};
