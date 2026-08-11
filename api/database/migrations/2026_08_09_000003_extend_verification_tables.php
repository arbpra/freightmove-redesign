<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in what the verification tables need to be usable in anger.
 *
 * `verification_documents` recorded only a path and a status. A reviewer opening
 * the queue needs to know what the file claims to be before opening it, and an
 * audit later needs to know what was actually stored — hence the original name,
 * MIME type and size. Stored filenames are randomised, so the original name is
 * the only human-readable handle on a document.
 *
 * `user_profiles` had a verification_status but nothing to say when it changed
 * or why, which makes a rejection impossible to explain to the carrier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_documents', function (Blueprint $table) {
            $table->string('original_name', 255)->nullable()->after('file_path');
            $table->string('mime_type', 128)->nullable()->after('original_name');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            $table->timestamp('reviewed_at')->nullable()->after('review_note');
            // Certificates of currency lapse; a document that was valid in
            // March is not evidence of anything in December.
            $table->date('expires_at')->nullable()->after('reviewed_at');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestamp('verification_submitted_at')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_submitted_at');
            // Why a decision went the way it did. Shown to the carrier on a
            // rejection, so it has to be written for them, not for us.
            $table->text('verification_note')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('verification_documents', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'mime_type', 'size_bytes', 'reviewed_at', 'expires_at']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['verification_submitted_at', 'verified_at', 'verification_note']);
        });
    }
};
