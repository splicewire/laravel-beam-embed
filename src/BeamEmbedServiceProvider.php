<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Embed\Types\EmbedType;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Embed\Types\GenericEmbedType;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

/**
 * The generic embed subsystem provider (recohere T02; generalized T30). Binds the NO-OP / default
 * inversion ports so a bare beam site gets the whole REMOTE-EMBED substrate out of the box — spawn a
 * session, publish, mint a capability grant, broker + audit a call — with no grounding, which is the
 * correct posture for a substrate that has no knowledge relations. A host (tower-embed's
 * TowerEmbedServiceProvider) OVERRIDES the knowledge/scope/audit bindings AND registers its own embed
 * TYPE ('concierge') on the registry this provider stands up.
 *
 * T30: "remote embed" is now the beam-tier capability and a concrete flavour (concierge) is ONE
 * registered {@see EmbedType}. This provider registers the
 * {@see EmbedTypeRegistry} singleton with the {@see GenericEmbedType} default (deny-by-default handlers);
 * the generic {@see CapabilityBroker} dispatches to the ACTIVE type's
 * handlers, and audits through the {@see CapabilityAuditRecorder} port.
 */
class BeamEmbedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KnowledgePivotFreezer::class, NullKnowledgePivotFreezer::class);
        $this->app->bind(ScopeResolver::class, NullScopeResolver::class);
        $this->app->bind(SubmissionRecorder::class, NullSubmissionRecorder::class);
        $this->app->bind(CapabilityAuditRecorder::class, DefaultCapabilityAuditRecorder::class);

        $this->app->singleton(EmbedTypeRegistry::class, function () {
            $registry = new EmbedTypeRegistry;
            $registry->register(new GenericEmbedType);

            return $registry;
        });
    }

    public function boot(): void
    {
        // Routes/controllers/config stay with the host (tower-embed); the embed-OWNED tenant
        // migrations are published from here (see bootMigrations()).
        if ($this->app->runningInConsole()) {
            $this->bootMigrations();
        }
    }

    /**
     * PUBLISH-ONLY tenant migrations — the idiomatic pattern for a PLAIN ServiceProvider, mirroring
     * the beam-workflows exemplar (commit 994aba1). Undo of the recohere runtime `--path` push.
     *
     * A plain provider has no spatie/laravel-package-tools machinery, so this uses Laravel's native
     * {@see ServiceProvider::publishesMigrations()} (Laravel 11+). It does NOT loadMigrationsFrom and
     * does NOT push onto `tenancy.migration_parameters.--path`: the package never runs these at
     * runtime. `vendor:publish --tag=beam-embed-migrations` drops the copies into the HOST's
     * `database/migrations/tenant/`, and the host's Stancl tenant pass runs them.
     *
     * TENANT-ONLY. `embed_spend_reservations` (prepaid-wallet holds, DIE-12) and
     * `embed_capability_audits` (brokered-capability audit trail, RCP-05) are both written on the
     * current tenant connection — tenancy is already initialized (InitializeTenancyByEmbedKey) before
     * either table is touched — so they publish ONLY into `database/migrations/tenant/`: there is NO
     * flat/central twin (that would wrongly create these tables in the central schema) and no
     * `Schema::hasTable()` dup-guard (that guard is only for a ubiquitous table's tenant twin; these
     * leaf tables have no central pass to collide with).
     *
     * The publishable source files carry their own valid timestamp prefix and ship as plain `.php`.
     * With `database.migrations.update_date_on_publish` at its default (false), native
     * `publishesMigrations` copies each file verbatim — one correctly-timestamped migration per table,
     * no double-stamp.
     */
    protected function bootMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/tenant' => $this->app->databasePath('migrations/tenant'),
        ], 'beam-embed-migrations');
    }
}
