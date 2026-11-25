<?php

namespace Splicewire\Beam\Embed\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Contracts\EmbedSourceResolver;
use Splicewire\Beam\Embed\EmbedPublisher;
use Splicewire\Beam\Embed\Models\Embed;
use Splicewire\Beam\Embed\Models\Thread;
use Splicewire\Beam\Embed\Sources\EmbedSourceRegistry;
use Splicewire\Beam\Embed\Sources\EmbedSourceResolution;
use Splicewire\Beam\Embed\Tests\TestCase;
use Splicewire\Beam\Embed\Types\EmbedType;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;

class EmbedPublisherCreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('embeds');
        Schema::dropIfExists('or35_embed_probe_sources');
        Schema::dropIfExists('beam_thread_participants');
        Schema::create('embeds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('source_type');
            $table->uuid('source_id');
            $table->uuid('user_id')->nullable();
            $table->string('visibility')->nullable();
            $table->json('embed_policy')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestamps();
        });
        Schema::create('or35_embed_probe_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    public function test_publisher_create_persists_an_embed_with_a_generated_distinct_uuid(): void
    {
        $source = new class extends Model
        {
            protected $table = 'or35_embed_probe_sources';

            protected $guarded = [];

            public $incrementing = false;

            protected $keyType = 'string';

            public function getMorphClass(): string
            {
                return 'or35-probe-source';
            }
        };
        $source->id = '5e131aa1-4e3c-4d47-9b41-581f5b98fce1';
        $source->save();

        $sources = new EmbedSourceRegistry;
        $sources->register('or35-probe-source', new class implements EmbedSourceResolver
        {
            public function resolve(Model $source): EmbedSourceResolution
            {
                return new EmbedSourceResolution('Probe', 'probe');
            }
        });
        $types = new EmbedTypeRegistry;
        $types->register(new class implements EmbedType
        {
            public function key(): string
            {
                return 'probe';
            }

            public function capabilityHandlers(): array
            {
                return [];
            }

            public function brokerClass(): string
            {
                return CapabilityBroker::class;
            }

            public function sourceKinds(): array
            {
                return ['or35-probe-source'];
            }
        });

        $embed = (new EmbedPublisher($sources, $types))->create($source);

        $this->assertNotNull($embed->getKey());
        $this->assertNotSame($source->getKey(), $embed->getKey());
        $this->assertDatabaseHas('embeds', [
            'id' => $embed->getKey(),
            'type' => 'probe',
            'source_type' => 'or35-probe-source',
            'source_id' => $source->getKey(),
        ]);

        config(['beam.threads.tables.threads' => 'threads']);
        if (! Schema::hasTable('threads')) {
            Schema::create('threads', function (Blueprint $table): void {
                $table->uuid('id')->primary();
            });
        }
        $this->assertNull(Thread::find($embed->getKey()), 'Creating an embed from another source must not create a Thread row.');
    }

    public function test_versionable_record_resolves_the_persisted_thread_source(): void
    {
        config(['beam.threads.tables.threads' => 'or35_embed_probe_sources']);
        Relation::morphMap(['thread' => Thread::class], true);
        Schema::create('beam_thread_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('role');
            $table->string('actor_type');
            $table->timestamps();
        });

        $source = new Thread;
        $source->title = 'A real source conversation';
        $source->save();

        $embed = new Embed;
        $embed->type = 'probe';
        $embed->source()->associate($source);
        $embed->save();

        $record = $embed->fresh()->versionableRecord();

        $this->assertInstanceOf(Thread::class, $record);
        $this->assertSame($source->getKey(), $record->getKey());
        $this->assertNotSame($source, $record);
    }

    public function test_a_null_embed_column_does_not_fall_back_to_the_source(): void
    {
        $source = new class extends Model
        {
            protected $table = 'or35_embed_probe_sources';

            protected $guarded = [];

            public $incrementing = false;

            protected $keyType = 'string';

            public function getMorphClass(): string
            {
                return 'or35-probe-source';
            }
        };
        $source->id = '5e131aa1-4e3c-4d47-9b41-581f5b98fce1';
        $source->user_id = '9d031ea8-4509-4eb3-8f1e-0d6f1f916f5e';
        $source->save();

        $embed = new Embed;
        $embed->source()->associate($source);

        $this->assertNull($embed->user_id);
    }
}
