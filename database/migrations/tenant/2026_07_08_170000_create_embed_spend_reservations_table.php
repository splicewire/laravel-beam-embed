<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid-wallet spend reservations (DIE-12): a short-lived, in-flight hold placed on
 * an Embed's prepaid balance between the budget gate and the actual debit, so a flood
 * of concurrent public requests against one `pk_` can't all read headroom before any
 * of them bills. The EmbedUsageMeter counts active (non-expired) reservations against
 * the wallet; a reservation is settled (deleted) when its stream completes, and
 * auto-expires if the stream crashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_spend_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('embed_key_id')->index();
            $table->uuid('session_id')->nullable()->index();
            $table->decimal('estimated_usd', 12, 4);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_spend_reservations');
    }
};
