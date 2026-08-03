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
use Splicewire\Tower\Policies\EmbedPolicy;
use Splicewire\Tower\TowerServiceProvider;

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
        // migrations are homed here (see bootMigrations()).
        $this->bootMigrations();

        // BC alias for the pre-recohere home (recohere Lane A cluster 7): EmbedPolicy moved down
        // from Splicewire\Tower\Policies to Splicewire\Beam\Embed\Policies.
        if (! class_exists(EmbedPolicy::class, false)) {
            class_alias(
                Policies\EmbedPolicy::class,
                EmbedPolicy::class,
            );
        }
    }

    /**
     * Home the embed-OWNED tenant migrations in this package so it self-provisions (recohere
     * migration relocation sweep).
     *
     * TENANT-ONLY. `embed_spend_reservations` (prepaid-wallet holds, DIE-12) and
     * `embed_capability_audits` (brokered-capability audit trail, RCP-05) are both written on the
     * current tenant connection — tenancy is already initialized (InitializeTenancyByEmbedKey)
     * before either table is touched — so there is NO central {@see loadMigrationsFrom()}; adding
     * one would wrongly create these tables in the central schema. Stancl tenancy has no
     * auto-discovery for tenant migrations; `tenants:migrate` reads the ARRAY at
     * `config('tenancy.migration_parameters.--path')` at runtime, so we push this package's
     * `database/migrations/tenant` dir onto it (install-location-agnostic, idempotent). Boot runs at
     * app-bootstrap, well before the command reads config, so ordering holds. Mirrors the tenant
     * half of {@see BeamWorkflowsServiceProvider::bootMigrations()} and
     * {@see TowerServiceProvider::bootMigrations()}.
     *
     * Gated by `config('beam.embed.register_migrations', true)` — defaults on, matching the
     * tower/beam-workflows exemplar's opt-out shape.
     */
    protected function bootMigrations(): void
    {
        if (! config('beam.embed.register_migrations', true)) {
            return;
        }

        $tenantDir = realpath(__DIR__.'/../database/migrations/tenant')
            ?: __DIR__.'/../database/migrations/tenant';

        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($tenantDir, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $tenantDir);
        }
    }
}
