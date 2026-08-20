<?php

namespace App\Libraries;

use App\Models\GenreModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class AssetTaxonomyService
{
    public const TYPES = ['featured', 'ads', 'trailer'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return list<object> */
    public function genres(bool $activeOnly = false): array
    {
        $model = new GenreModel();
        if ($activeOnly) $model->where('status', 'active');
        return $model->orderBy('name')->findAll();
    }

    /** @param mixed $input @return list<int> */
    public function validateGenreIds(mixed $input): array
    {
        $ids = is_array($input) ? array_values(array_unique(array_filter(array_map('intval', $input), static fn (int $id): bool => $id > 0))) : [];
        if (count($ids) > 12) throw new RuntimeException('Choose at most 12 genres.');
        if ($ids === []) return [];
        $valid = (new GenreModel())->where('status', 'active')->whereIn('id', $ids)->findAll();
        if (count($valid) !== count($ids)) throw new RuntimeException('One or more selected genres are unavailable.');
        return $ids;
    }

    /** @param list<int> $genreIds @return list<string> */
    public function namesForIds(array $genreIds): array
    {
        if ($genreIds === []) return [];
        $byId = [];
        foreach ((new GenreModel())->whereIn('id', $genreIds)->findAll() as $genre) $byId[(int) $genre->id] = $genre->name;
        return array_values(array_filter(array_map(static fn (int $id): ?string => $byId[$id] ?? null, $genreIds)));
    }

    /** @param list<int> $genreIds */
    public function sync(int $assetId, array $genreIds): void
    {
        $this->db->table('asset_genres')->where('asset_id', $assetId)->delete();
        foreach ($genreIds as $genreId) {
            if (! $this->db->table('asset_genres')->insert(['asset_id' => $assetId, 'genre_id' => $genreId, 'created_at' => gmdate('Y-m-d H:i:s')])) {
                throw new RuntimeException('Asset genres could not be stored.');
            }
        }
    }

    /** @param list<int> $assetIds @return array<int,list<array{id:int,name:string,slug:string,status:string}>> */
    public function mapForAssets(array $assetIds): array
    {
        if ($assetIds === []) return [];
        $rows = $this->db->table('asset_genres ag')->select('ag.asset_id, g.id, g.name, g.slug, g.status')
            ->join('genres g', 'g.id = ag.genre_id')->whereIn('ag.asset_id', $assetIds)->orderBy('g.name')->get()->getResultArray();
        $map = [];
        foreach ($rows as $row) $map[(int) $row['asset_id']][] = ['id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'status' => $row['status']];
        return $map;
    }

    public function createGenre(string $name, int $createdBy): object
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Genre name is required and must not exceed 80 characters.');
        $slug = $this->slug($name);
        if ((new GenreModel())->where('slug', $slug)->first() !== null) throw new RuntimeException('That genre already exists.');
        $id = (new GenreModel())->insert(['public_id' => $this->uuidV4(), 'name' => $name, 'slug' => $slug, 'status' => 'active', 'created_by' => $createdBy], true);
        if (! is_int($id)) throw new RuntimeException('Genre could not be created.');
        return (new GenreModel())->find($id);
    }

    private function slug(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $slug);
        return trim($slug, '-') ?: 'genre-' . substr(hash('sha256', $value), 0, 12);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
