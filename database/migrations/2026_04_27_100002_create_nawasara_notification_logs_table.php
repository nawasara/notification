<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // tracking id, dipakai webhook delivery callback nanti
            $table->foreignId('template_id')->nullable()->constrained('nawasara_notification_templates')->nullOnDelete();
            $table->string('template_key', 100)->nullable(); // snapshot kalau template di-delete
            $table->string('channel', 50);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient', 255); // email / phone / chat_id
            $table->string('subject', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 20)->default('queued'); // queued, sending, sent, delivered, failed, bounced
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('context')->nullable(); // source package, event data, custom metadata
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('user_id');
            $table->index('recipient');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_notification_logs');
    }
};
