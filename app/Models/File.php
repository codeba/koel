<?php

namespace App\Models;

use App\Repositories\SongRepository;
use App\Services\HelperService;
use App\Services\MediaMetadataService;
use Cache;
use Exception;
use getID3;
use getid3_exception;
use getid3_lib;
use InvalidArgumentException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class File
{
    const SYNC_RESULT_SUCCESS = 1;
    const SYNC_RESULT_BAD_FILE = 2;
    const SYNC_RESULT_UNMODIFIED = 3;

    /**
     * A MD5 hash of the file's path.
     * This value is unique, and can be used to query a Song record.
     *
     * @var string
     */
    protected $hash;

    /**
     * The file's last modified time.
     *
     * @var int
     */
    protected $mtime;

    /**
     * The file's path.
     */
    protected $path;

    /**
     * The getID3 object, for ID3 tag reading.
     */
    protected $getID3;

    /**
     * @var MediaMetadataService
     */
    private $mediaMetadataService;

    /**
     * The SplFileInfo object of the file.
     *
     * @var SplFileInfo
     */
    protected $splFileInfo;

    /**
     * The song model that's associated with this file.
     *
     * @var Song
     */
    protected $song;

    /**
     * The last parsing error text, if any.
     *
     * @var string
     */
    protected $syncError;

    /**
     * @var HelperService
     */
    private $helperService;

    /**
     * @var SongRepository
     */
    private $songRepository;

    /**
     * Construct our File object.
     * Upon construction, we'll set the path, hash, and associated Song object (if any).
     *
     * @param string|SplFileInfo $path Either the file's path, or a SplFileInfo object
     *
     * @throws getid3_exception
     *
     * @todo Refactor this bloated, anti-pattern monster.
     */
    public function __construct(
        $path,
        ?getID3 $getID3 = null,
        ?MediaMetadataService $mediaMetadataService = null,
        ?HelperService $helperService = null,
        ?SongRepository $songRepository = null
    ) {
        $this->splFileInfo = $path instanceof SplFileInfo ? $path : new SplFileInfo($path);
        $this->setGetID3($getID3);
        $this->setMediaMetadataService($mediaMetadataService);
        $this->setHelperService($helperService);
        $this->setSongRepository($songRepository);

        // Workaround for #344, where getMTime() fails for certain files with Unicode names on Windows.
        try {
            $this->mtime = $this->splFileInfo->getMTime();
        } catch (Exception $e) {
            // Not worth logging the error. Just use current stamp for mtime.
            $this->mtime = time();
        }

        $this->path = $this->splFileInfo->getPathname();
        $this->hash = $this->helperService->getFileHash($this->path);
        $this->song = $this->songRepository->getOneById($this->hash);
        $this->syncError = null;
    }

    /**
     * Get all applicable ID3 info from the file.
     */
    public function getInfo(): array
    {
        $info = $this->getID3->analyze($this->path);

        if (isset($info['error']) || !isset($info['playtime_seconds'])) {
            $this->syncError = isset($info['error']) ? $info['error'][0] : 'No playtime found';

            return [];
        }

        // Copy the available tags over to comment.
        // This is a helper from getID3, though it doesn't really work well.
        // We'll still prefer getting ID3v2 tags directly later.
        // Read on.
        getid3_lib::CopyTagsToComments($info);

        $track = 0;

        // Apparently track number can be stored with different indices as the following.
        $trackIndices = [
            'comments.track',
            'comments.tracknumber',
            'comments.track_number',
        ];

        for ($i = 0; $i < count($trackIndices) && $track === 0; $i++) {
            $track = array_get($info, $trackIndices[$i], [0])[0];
        }

        $props = [
            'artist' => '',
            'album' => '',
            'compilation' => false,
            'title' => basename($this->path, '.'.pathinfo($this->path, PATHINFO_EXTENSION)), // default to be file name
            'length' => $info['playtime_seconds'],
            'track' => (int) $track,
            'disc' => (int) array_get($info, 'comments.part_of_a_set.0', 1),
            'lyrics' => '',
            'cover' => array_get($info, 'comments.picture', [null])[0],
            'path' => $this->path,
            'mtime' => $this->mtime,
        ];

        if (!$comments = array_get($info, 'comments_html')) {
            return $props;
        }

        $propertyMap = [
            'artist' => 'artist',
            'albumartist' => 'band',
            'album' => 'album',
            'title' => 'title',
            'lyrics' => 'unsychronised_lyric', // this tag name is mispelled by getID3
            'compilation' => 'part_of_a_compilation',
        ];

        foreach ($propertyMap as $name => $tag) {
            $props[$name] = array_get($info, "tags.id3v2.$tag", [null])[0] ?: array_get($comments, $tag, [''])[0];
            // Fixes #323, where tag names can be htmlentities()'ed
            if (is_string($props[$name]) && $props[$name]) {
                $props[$name] = trim(html_entity_decode($props[$name]));
            }
        }

        // A "compilation" property can be determined by:
        // - "part_of_a_compilation" tag (used by iTunes), or
        // - "albumartist" (used by non-retarded applications).
        // Also, the latter is only valid if the value is NOT the same as "artist".
        if (!$props['compilation']) {
            $props['compilation'] = $props['albumartist'] && $props['artist'] !== $props['albumartist'];
        }

        return $props;
    }

    /**
     * Sync the song with all available media info against the database.
     *
     * @param string[] $tags  The (selective) tags to sync (if the song exists)
     * @param bool     $force Whether to force syncing, even if the file is unchanged
     *
     * @return bool|Song A Song object on success,
     *                   true if file exists but is unmodified,
     *                   or false on an error.
     */
    public function sync(array $tags, bool $force = false)
    {
        // If the file is not new or changed and we're not forcing update, don't do anything.
        if (!$this->isNewOrChanged() && !$force) {
            return self::SYNC_RESULT_UNMODIFIED;
        }

        // If the file is invalid, don't do anything.
        if (!$info = $this->getInfo()) {
            return self::SYNC_RESULT_BAD_FILE;
        }

        // Fixes #366. If the file is new, we use all tags by simply setting $force to false.
        if ($this->isNew()) {
            $force = false;
        }

        if ($this->isChanged() || $force) {
            // This is a changed file, or the user is forcing updates.
            // In such a case, the user must have specified a list of tags to sync.
            // A sample command could be: ./artisan koel:sync --force --tags=artist,album,lyrics
            // We cater for these tags by removing those not specified.

            // There's a special case with 'album' though.
            // If 'compilation' tag is specified, 'album' must be counted in as well.
            // But if 'album' isn't specified, we don't want to update normal albums.
            // This variable is to keep track of this state.
            $changeCompilationAlbumOnly = false;
            if (in_array('compilation', $tags, true) && !in_array('album', $tags, true)) {
                $tags[] = 'album';
                $changeCompilationAlbumOnly = true;
            }

            $info = array_intersect_key($info, array_flip($tags));

            // If the "artist" tag is specified, use it.
            // Otherwise, re-use the existing model value.
            $artist = isset($info['artist']) ? Artist::get($info['artist']) : $this->song->album->artist;

            $isCompilation = (bool) array_get($info, 'compilation');

            // If the "album" tag is specified, use it.
            // Otherwise, re-use the existing model value.
            if (isset($info['album'])) {
                $album = $changeCompilationAlbumOnly
                    ? $this->song->album
                    : Album::get($artist, $info['album'], $isCompilation);
            } else {
                $album = $this->song->album;
            }
        } else {
            // The file is newly added.
            $isCompilation = (bool) array_get($info, 'compilation');
            $artist = Artist::get($info['artist']);
            $album = Album::get($artist, $info['album'], $isCompilation);
        }

        $album->has_cover || $this->generateAlbumCover($album, array_get($info, 'cover'));

        $data = array_except($info, ['artist', 'albumartist', 'album', 'cover', 'compilation']);
        $data['album_id'] = $album->id;
        $data['artist_id'] = $artist->id;
        $this->song = Song::updateOrCreate(['id' => $this->hash], $data);

        return self::SYNC_RESULT_SUCCESS;
    }

    /**
     * Try to generate a cover for an album based on extracted data, or use the cover file under the directory.
     *
     * @param mixed[]|null $coverData
     */
    private function generateAlbumCover(Album $album, ?array $coverData): void
    {
        // If the album has no cover, we try to get the cover image from existing tag data
        if ($coverData) {
            $extension = explode('/', $coverData['image_mime']);
            $extension = empty($extension[1]) ? 'png' : $extension[1];

            $this->mediaMetadataService->writeAlbumCover($album, $coverData['data'], $extension);

            return;
        }

        // Or, if there's a cover image under the same directory, use it.
        if ($cover = $this->getCoverFileUnderSameDirectory()) {
            $this->mediaMetadataService->copyAlbumCover($album, $cover);
        }
    }

    /**
     * Determine if the file is new (its Song record can't be found in the database).
     */
    public function isNew(): bool
    {
        return !$this->song;
    }

    /**
     * Determine if the file is changed (its Song record is found, but the timestamp is different).
     */
    public function isChanged(): bool
    {
        return !$this->isNew() && $this->song->mtime !== $this->mtime;
    }

    /**
     * Determine if the file is new or changed.
     */
    public function isNewOrChanged(): bool
    {
        return $this->isNew() || $this->isChanged();
    }

    public function getGetID3(): getID3
    {
        return $this->getID3;
    }

    /**
     * Get the last parsing error's text.
     */
    public function getSyncError(): ?string
    {
        return $this->syncError;
    }

    /**
     * @throws getid3_exception
     */
    public function setGetID3(?getID3 $getID3 = null): void
    {
        $this->getID3 = $getID3 ?: new getID3();
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Issue #380.
     * Some albums have its own cover image under the same directory as cover|folder.jpg/png.
     * We'll check if such a cover file is found, and use it if positive.
     *
     * @throws InvalidArgumentException
     */
    private function getCoverFileUnderSameDirectory(): ?string
    {
        // As directory scanning can be expensive, we cache and reuse the result.
        return Cache::remember(md5($this->path.'_cover'), 24 * 60, function () {
            $matches = array_keys(iterator_to_array(
                Finder::create()
                    ->depth(0)
                    ->ignoreUnreadableDirs()
                    ->files()
                    ->followLinks()
                    ->name('/(cov|fold)er\.(jpe?g|png)$/i')
                    ->in(dirname($this->path))
                )
            );

            $cover = $matches ? $matches[0] : null;

            // Even if a file is found, make sure it's a real image.
            if ($cover && exif_imagetype($cover) === false) {
                $cover = null;
            }

            return $cover;
        });
    }

    private function setMediaMetadataService(?MediaMetadataService $mediaMetadataService = null): void
    {
        $this->mediaMetadataService = $mediaMetadataService ?: app(MediaMetadataService::class);
    }

    private function setHelperService(?HelperService $helperService = null): void
    {
        $this->helperService = $helperService ?: app(HelperService::class);
    }

    public function setSongRepository(?SongRepository $songRepository = null): void
    {
        $this->songRepository = $songRepository ?: app(SongRepository::class);
    }
}
