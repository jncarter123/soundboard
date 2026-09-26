<?php

namespace Tests\Feature;

use App\Livewire\Admin\Metrics;
use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PresenceMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reverb.metrics.host' => '127.0.0.1', 'reverb.metrics.port' => 8080, 'reverb.metrics.scheme' => 'http']);
        ReverbApp::create([
            'name' => 'Chat', 'app_id' => 'chat', 'key' => 'chat-key', 'secret' => 'chat-secret', 'allowed_origins' => ['*'],
        ]);
    }

    /** @var list<string> */
    protected array $members = ['1', '2', '3'];

    protected bool $presenceListed = true;

    protected bool $membersFail = false;

    /**
     * One fake for the whole test, answering from the properties above, so a
     * test can change what Reverb reports between calls. Response shapes are
     * Reverb's: user_count for presence channels, subscription_count otherwise.
     */
    protected function fakeReverb(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/users')) {
                return $this->membersFail
                    ? Http::response('', 500)
                    : Http::response(['users' => array_map(fn ($id) => ['id' => $id], $this->members)]);
            }

            if (str_contains($url, '/channels?')) {
                $channels = ['announcements' => ['subscription_count' => 4]];

                if ($this->presenceListed) {
                    $channels['presence-room.1'] = ['user_count' => count($this->members)];
                }

                return Http::response(['channels' => $channels]);
            }

            return Http::response(['connections' => 4]);
        });
    }

    public function test_channel_list_asks_for_member_counts(): void
    {
        $this->fakeReverb();

        app(ReverbApiService::class)->getAllAppsLiveStats();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/channels?')
            && str_contains(urldecode($r->url()), 'info=subscription_count,user_count'));
    }

    public function test_presence_channels_show_member_counts_not_a_dash(): void
    {
        $this->fakeReverb();

        Livewire::test(Metrics::class)
            ->assertSeeInOrder(['presence-room.1', 'Presence', '3', 'members'])
            ->assertSeeInOrder(['announcements', 'Public', '4']);
    }

    public function test_members_toggle_open_refresh_and_close(): void
    {
        $this->fakeReverb();

        $component = Livewire::test(Metrics::class)
            ->call('toggleMembers', 'chat', 'presence-room.1')
            ->assertSet('membersOf', ['chat', 'presence-room.1'])
            ->assertSet('members', ['1', '2', '3'])
            ->assertSee('Member user IDs');

        // The live refresh keeps the open list current.
        $this->members = ['1', '2', '3', '9'];
        $component->call('refreshLive')->assertSet('members', ['1', '2', '3', '9']);

        $component->call('toggleMembers', 'chat', 'presence-room.1')
            ->assertSet('membersOf', null)
            ->assertDontSee('Member user IDs');
    }

    public function test_list_closes_when_the_channel_empties(): void
    {
        $this->fakeReverb();
        $component = Livewire::test(Metrics::class)->call('toggleMembers', 'chat', 'presence-room.1');

        // Reverb drops a channel once its last member leaves.
        $this->presenceListed = false;
        $component->call('refreshLive')->assertSet('membersOf', null)->assertSet('members', null);
    }

    public function test_only_listed_presence_channels_can_be_opened(): void
    {
        $this->fakeReverb();
        $component = Livewire::test(Metrics::class);

        $component->call('toggleMembers', 'chat', 'announcements')->assertSet('membersOf', null);
        $component->call('toggleMembers', 'chat', 'presence-not-listed')->assertSet('membersOf', null);
        $component->call('toggleMembers', 'other-app', 'presence-room.1')->assertSet('membersOf', null);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users'));
    }

    public function test_a_failed_member_lookup_says_so(): void
    {
        $this->fakeReverb();
        $component = Livewire::test(Metrics::class);

        $this->membersFail = true;

        $component->call('toggleMembers', 'chat', 'presence-room.1')
            ->assertSet('members', null)
            ->assertSeeHtml("Couldn't load members from Reverb.");
    }
}
