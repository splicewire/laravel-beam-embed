<?php

namespace Splicewire\Beam\Embed\Tests\Data;

use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Embed\Data\PrunePreviewData;
use Splicewire\Beam\Embed\Data\RetentionPostureChatData;
use Splicewire\Beam\Embed\Data\RetentionPostureData;
use Splicewire\Beam\Embed\Tests\TestCase;

class RetentionWireNamesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelDataServiceProvider::class];
    }

    public function test_snake_inputs_populate_camel_properties_and_responses_keep_snake_keys(): void
    {
        $chat = RetentionPostureChatData::from(['id' => 'chat-1', 'title' => null, 'retention_days' => 30, 'corpus_optin' => false]);
        $posture = RetentionPostureData::from(['default_days' => 90, 'chats' => [$chat]]);
        $preview = PrunePreviewData::from(['would_prune' => 7]);

        $this->assertSame(30, $chat->retentionDays);
        $this->assertFalse($chat->corpusOptin);
        $this->assertSame(90, $posture->defaultDays);
        $this->assertSame(7, $preview->wouldPrune);
        $this->assertSame(['default_days' => 90, 'chats' => [
            ['id' => 'chat-1', 'title' => null, 'retention_days' => 30, 'corpus_optin' => false],
        ]], $posture->toArray());
        $this->assertSame(['would_prune' => 7], $preview->toArray());
    }

    public function test_direct_camel_construction_still_serializes_the_published_names(): void
    {
        $chat = new RetentionPostureChatData(id: 'chat-2', title: 'Keep', retentionDays: 14, corpusOptin: true);
        $posture = new RetentionPostureData(defaultDays: 60, chats: [$chat]);
        $this->assertSame(['default_days' => 60, 'chats' => [
            ['id' => 'chat-2', 'title' => 'Keep', 'retention_days' => 14, 'corpus_optin' => true],
        ]], $posture->toArray());
        $this->assertSame(['would_prune' => 0], (new PrunePreviewData(wouldPrune: 0))->toArray());
    }
}
