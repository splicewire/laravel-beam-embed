<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Embed\Doctor\BeamEmbedMigrationsAudit;
use Splicewire\Beam\Embed\Models\Embed;
use Splicewire\Beam\Embed\Policies\EmbedPolicy;
use Splicewire\Beam\Embed\Types\EmbedType;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Embed\Types\GenericEmbedType;
use Splicewire\Beam\Install\BeamInstallManifest;

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
 *
 * beam-embed's TENANT tables (`embed_spend_reservations`, `embed_capability_audits`, `embed_sessions`)
 * ship as PUBLISH-ONLY spatie/laravel-package-tools stubs — the idiomatic pattern for a
 * PackageServiceProvider. `runsMigrations` stays FALSE, so beam-embed never loads them at runtime;
 * `vendor:publish --tag=beam-embed-migrations` re-stamps + sequences timestamped copies into the HOST's
 * `database/migrations/tenant/` at install time, which the host's Stancl tenant pass runs. None of these
 * three tables is ubiquitous (no central pass, no `tenant/` twin needed) — each ships as ONE stub already
 * living under `database/migrations/tenant/`, registered via `->hasMigrations([...])` in
 * {@see self::configurePackage()}.
 */
class BeamEmbedServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-embed')
            // TENANT-ONLY leaf tables (no central pass, no flat twin): each stub already lives under
            // database/migrations/tenant/, and `hasMigrations([...])` publishes it there re-stamped to
            // the install moment. Declared order matches the original timestamp order.
            ->hasMigrations([
                'tenant/create_embed_spend_reservations_table',
                'tenant/create_embed_capability_audit_table',
                'tenant/create_embed_sessions_table',
            ]);
    }

    public function packageRegistered(): void
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

    public function packageBooted(): void
    {
        // Routes/controllers/config stay with the host (tower-embed); the embed-OWNED tenant migrations
        // publish via configurePackage()'s ->hasMigrations([...]) above.

        // The `embed` morph alias — the wire identifier this package's polymorphic rows store, and the
        // permission-token prefix that mints the `embed.*` family (ADR-0118). The package that OWNS the
        // model owns its alias; a host should only have to declare aliases for its OWN models.
        // {@see Embed::getMorphClass()} already pins the write side; this supplies the read side, so a
        // `morphTo()` landing on an `embed` row resolves back to the class (and `array_search($class,
        // Relation::$morphMap)` reverse lookups stop missing it).
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: a beam-composing host has many
        // models on class-string morphs and global strict mode rejects every one of them. Mirrors
        // {@see \Splicewire\Beam\BeamServiceProvider}.
        Relation::morphMap(['embed' => Embed::class]);

        // …and this package's own policy binding, for the same reason the alias belongs here: the
        // package that owns the model owns its authorization, so a host does not have to know an
        // Embed needs a policy. Previously declared in splicewire-app's AuthServiceProvider `$policies`
        // map, which meant every composing host had to restate it (and the host's map boots LAST, so a
        // stale entry there silently clobbered the correct binding — a bug that comment records having
        // already happened once).
        //
        // Stays a hand-written class rather than converting to `#[UseCascadePolicy]`: EmbedPolicy adds
        // `rotateKey`/`revokeKey`, which CASCADE (`canCascade(…, 'rotate-key')`). The attribute only
        // expresses unconditional literal answers, and any ability it does not name falls through to
        // `ConfiguredModelPolicy::__call()`, which returns false — so converting would silently deny
        // every key rotation.
        Gate::policy(Embed::class, EmbedPolicy::class);

        // beam-embed self-registers its own install step DOWN into beam-core's install manifest, same
        // as any other beam-* package — the publish-only tenant migration stubs.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-embed',
                publishTags: ['beam-embed-migrations'],
                migrates: true,
            );
        }

        // beam-embed is itself an "operator" of the estate-wide publish-only stub migrations convention
        // (BeamServiceProvider docblock, laravel-beam) — self-registers the doctor/operator check on ITS
        // OWN migrations, same as every other beam-* package registers it on theirs.
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-embed',
                BeamEmbedMigrationsAudit::class,
            );
        }
    }
}
