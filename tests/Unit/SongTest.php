<?php

namespace Tests\Unit;

use App\Facades\YouTube;
use App\Http\Requests\API\ObjectStorage\S3\Request;
use App\Models\Song;
use App\Models\User;
use Aws\AwsClient;
use Cache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Lastfm;
use Mockery as m;
use Tests\TestCase;

class SongTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(Song::class, new Song());
    }

    /** @test */
    public function it_scrobbles_if_the_user_is_connected_to_lastfm()
    {
        // Given there's a song
        /** @var Song $song */
        $song = factory(Song::class)->create();

        // And a user who's connected to lastfm
        /** @var User $user */
        $user = factory(User::class)->create();
        $user->setPreference('lastfm_session_key', 'foo');

        // When I call the scrobble method
        $time = time();
        Lastfm::shouldReceive('scrobble')
            ->once()
            ->with($song->artist->name, $song->title, $time, $song->album->name, 'foo');

        $song->scrobble($user, $time);

        // Then I see the song is scrobbled
    }

    /** @test */
    public function it_does_not_scrobble_if_the_user_is_not_connected_to_lastfm()
    {
        // Given there's a song
        /** @var Song $song */
        $song = factory(Song::class)->create();

        // And a user who is not connected to lastfm
        /** @var User $user */
        $user = factory(User::class)->create();
        $user->setPreference('lastfm_session_key', false);

        // When I call the scrobble method
        Lastfm::shouldNotReceive('scrobble');

        $song->scrobble($user, time());

        // The the song shouldn't be scrobbled
    }

    /** @test */
    public function it_can_be_retrieved_using_its_path()
    {
        // Given a song with a path
        /** @var Song $song */
        $song = factory(Song::class)->create(['path' => 'foo']);

        // When I retrieve it using the path
        $retrieved = Song::byPath('foo');

        // Then the song is retrieved
        $this->assertEquals($song->id, $retrieved->id);
    }

    /** @test */
    public function it_returns_object_storage_public_url()
    {
        // Given there's a song hosted on Amazon S3
        /** @var Song $song */
        $song = factory(Song::class)->create(['path' => 's3://foo/bar']);
        $mockedURL = 'http://aws.com/foo/bar';

        // When I get the song's object storage public URL
        $client = m::mock(AwsClient::class, [
            'getCommand' => null,
            'createPresignedRequest' => m::mock(Request::class, [
                'getUri' => $mockedURL,
            ]),
        ]);

        Cache::shouldReceive('get')->once()->with("OSUrl/{$song->id}");
        Cache::shouldReceive('put')->once()->with("OSUrl/{$song->id}", $mockedURL, 60);
        $url = $song->getObjectStoragePublicUrl($client);

        // Then I should receive the correct S3 public URL
        $this->assertEquals($mockedURL, $url);
    }

    /** @test */
    public function it_gets_related_youtube_videos()
    {
        // Given there's a song
        /** @var Song $song */
        $song = factory(Song::class)->create();

        // When I get is related YouTube videos
        YouTube::shouldReceive('searchVideosRelatedToSong')
            ->once()
            ->with($song, 'foo')
            ->andReturn(['bar' => 'baz']);

        $videos = $song->getRelatedYouTubeVideos('foo');

        // Then I see the related YouTube videos returned
        $this->assertEquals(['bar' => 'baz'], $videos);
    }

    /** @test */
    public function its_lyrics_has_all_new_line_characters_replace_by_br_tags()
    {
        // Given a song with lyrics contains new line characters
        /** @var Song $song */
        $song = factory(Song::class)->create([
            'lyrics' => "foo\rbar\nbaz\r\nqux",
        ]);

        // When I retrieve its lyrics
        $lyrics = $song->lyrics;

        // Then I see the new line characters replaced by <br />
        $this->assertEquals('foo<br />bar<br />baz<br />qux', $lyrics);
    }

    /** @test */
    public function amazon_s3_parameters_can_be_retrieved_from_s3_hosted_songs()
    {
        // Given a song hosted on S3
        /** @var Song $song */
        $song = factory(Song::class)->create(['path' => 's3://foo/bar']);

        // When I check its S3 parameters
        $params = $song->s3_params;

        // Then I receive the correct parameters
        $this->assertEquals(['bucket' => 'foo', 'key' => 'bar'], $params);
    }
}
