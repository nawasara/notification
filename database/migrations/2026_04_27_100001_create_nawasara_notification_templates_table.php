<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Per-channel body. MVP cuma email; field lain disiapkan untuk future.
            $table->string('subject', 500)->nullable();
            $table->longText('body_email_html')->nullable();
            $table->longText('body_email_text')->nullable();
            $table->text('body_whatsapp')->nullable();
            $table->text('body_telegram')->nullable();
            $table->text('body_inapp')->nullable();

            // Channels yang aktif untuk template ini ['email', 'whatsapp']
            $table->json('channels');

            // Variable schema untuk validation + UI hint
            // [{name, type, required, description}]
            $table->json('variables')->nullable();

            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_notification_templates');
    }
};
