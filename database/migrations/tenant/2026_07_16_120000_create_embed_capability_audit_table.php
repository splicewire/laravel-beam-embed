<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brokered-capability audit trail (RCP-05): every capability an isolated remote guest
 * invokes through the host broker leaves a row here. The trust boundary is the host —
 * a guest never touches a credential or a raw fetch — so the host records what it ran
 * on the guest's behalf: the capability name, the embed session it was bound to, the
 * trust tier of the grant, whether it was allowed, and (on refusal) why.
 *
 * The row is written on the current tenant connection (tenancy is already initialized
 * by InitializeTenancyByEmbedKey before the broker runs), keeping the audit with the
 * tenant that owns the published chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_capability_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('embed_key_id')->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('capability')->index();
            $table->string('tier');
            $table->boolean('allowed');
            $table->string('refusal_reason')->nullable();
            $table->json('request')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_capability_audits');
    }
};
