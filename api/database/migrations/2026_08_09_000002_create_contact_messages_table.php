<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public contact form.
 *
 * Stored rather than only emailed. Mail is the part most likely to fail — an
 * expired SMTP credential, a provider outage, a spam filter — and an enquiry
 * that only ever existed inside a failed send is a lost customer. The row is
 * written first and the notification is best effort on top of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('phone', 32)->nullable();
            $table->string('role', 20)->nullable();
            $table->string('subject', 150)->nullable();
            $table->text('message');

            // Who sent it, for tracing abuse. Not shown to staff.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Set when the account is signed in, so staff can see the enquiry
            // is from an existing customer without matching on email.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // The admin queue reads newest-unhandled first.
            $table->index(['handled_at', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
