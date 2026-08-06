<?php

namespace App\Libraries;

use App\Entities\Device;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\AssetModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

class AssetInventoryService
{
    private DeviceAssetModel $deviceAssets;

    private DeviceModel $devices;

    private AssetModel $catalogAssets;

    private BaseConnection $db;

    public function __construct(
        ?DeviceAssetModel $deviceAssets = null,
        ?DeviceModel $devices = null,
        ?BaseConnection $db = null,
        ?AssetModel $catalogAssets = null,
    ) {
        $this->deviceAssets = $deviceAssets ?? new DeviceAssetModel();
        $this->devices = $devices ?? new DeviceModel();
        $this->db = $db ?? Database::connect();
        $this->catalogAssets = $catalogAssets ?? new AssetModel();
    }

    /**
     * @param list<array<string, mixed>> $assets
     * @return array<string, int|string>
     */
    public function sync(Device $device, array $assets): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $reportedKeys = [];
        $inserted = 0;
        $updated = 0;
        $markedMissing = 0;

        $this->db->transBegin();
        try {
            // Updating the Player row first serializes concurrent snapshots for
            // the same device on PostgreSQL. A rolled-back sync does not consume
            // a revision.
            $this->db->table('devices')
                ->where('id', $device->id)
                ->set('inventory_revision', 'inventory_revision + 1', false)
                ->set('updated_at', $now)
                ->update();
            if ($this->db->affectedRows() !== 1) {
                throw new RuntimeException('The Player inventory revision could not be updated.');
            }

            $existing = [];
            foreach ($this->deviceAssets->where('device_id', $device->id)->findAll() as $item) {
                $existing[$item->media_key] = $item;
            }

            foreach ($assets as $asset) {
                $mediaKey = (string) $asset['media_key'];
                $reportedKeys[$mediaKey] = true;
                $assetId = isset($existing[$mediaKey]) ? $existing[$mediaKey]->asset_id : null;
                $catalogAsset = null;
                if ($asset['source'] === 'managed') {
                    $catalogAsset = $assetId === null
                        ? $this->catalogAssets->where('public_id', substr($mediaKey, strlen('managed:')))->first()
                        : $this->catalogAssets->find($assetId);
                    $assetId = $catalogAsset?->id;
                    $reportedDuration = (int) $asset['duration_ms'];
                    $hashMatches = $catalogAsset !== null
                        && $asset['sha256'] !== null
                        && hash_equals((string) $catalogAsset->sha256, (string) $asset['sha256']);
                    if ($catalogAsset !== null && (int) $catalogAsset->duration_ms === 0
                        && $reportedDuration > 0 && $asset['status'] === 'ready' && $hashMatches) {
                        $this->db->table('assets')->where('id', $catalogAsset->id)->where('duration_ms', 0)
                            ->set('duration_ms', $reportedDuration)->set('updated_at', $now)->update();
                    }
                }
                $values = [
                    'device_id'        => $device->id,
                    'asset_id'         => $assetId,
                    'media_key'        => $mediaKey,
                    'source'           => $asset['source'],
                    'title'            => $asset['title'],
                    'filename'         => $asset['filename'],
                    'relative_path'    => $asset['relative_path'],
                    'size_bytes'       => $asset['size_bytes'],
                    'duration_ms'      => $asset['duration_ms'],
                    'sha256'           => $asset['sha256'],
                    'status'           => isset($existing[$mediaKey]) && $existing[$mediaKey]->status === 'removal_pending'
                        ? 'removal_pending' : $asset['status'],
                    'modified_at'      => $asset['modified_at'],
                    'last_reported_at' => $now,
                ];

                if (isset($existing[$mediaKey])) {
                    if (! $this->deviceAssets->update($existing[$mediaKey]->id, $values)) {
                        throw new RuntimeException('An existing asset inventory item could not be updated.');
                    }
                    $updated++;
                } else {
                    if ($this->deviceAssets->insert($values, false) === false) {
                        throw new RuntimeException('An asset inventory item could not be inserted.');
                    }
                    $inserted++;
                }
            }

            foreach ($existing as $mediaKey => $item) {
                if (isset($reportedKeys[$mediaKey])) {
                    continue;
                }
                if ($item->status === 'removal_pending') continue;
                if (! $this->deviceAssets->update($item->id, [
                    'status'           => 'missing',
                    'last_reported_at' => $now,
                ])) {
                    throw new RuntimeException('A removed asset could not be marked missing.');
                }
                if ($item->status !== 'missing') $markedMissing++;
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('The asset inventory transaction failed.');
            }
            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        $summary = ['total' => 0, 'ready' => 0, 'missing' => 0, 'problems' => 0];
        foreach ($this->deviceAssets->where('device_id', $device->id)->findAll() as $item) {
            $summary['total']++;
            if ($item->status === 'ready') {
                $summary['ready']++;
            } else {
                $summary['problems']++;
                if ($item->status === 'missing') {
                    $summary['missing']++;
                }
            }
        }

        /** @var Device $updatedDevice */
        $updatedDevice = $this->devices->find($device->id);

        return [
            'inventory_revision' => (int) $updatedDevice->inventory_revision,
            'reported'           => count($assets),
            'inserted'           => $inserted,
            'updated'            => $updated,
            'marked_missing'     => $markedMissing,
            ...$summary,
            'synced_at'          => gmdate(DATE_ATOM),
        ];
    }
}
