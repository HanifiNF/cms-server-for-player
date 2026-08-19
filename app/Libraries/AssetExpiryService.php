<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

class AssetExpiryService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array{expired:int,assignments:int,devices:int,date:string} */
    public function expireDue(?DateTimeImmutable $now = null): array
    {
        $clock = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
        $today = $clock->format('Y-m-d');
        $expiredCount = 0;
        $assignmentCount = 0;
        $affectedDevices = [];

        $candidates = $this->db->table('assets')
            ->select('id')->where('expires_on IS NOT NULL', null, false)
            ->where('expires_on <', $today)->whereIn('status', ['draft', 'active', 'rejected'])
            ->orderBy('id')->get()->getResultArray();

        $this->db->transBegin();
        try {
            foreach ($candidates as $candidate) {
                $assetId = (int) $candidate['id'];
                $this->db->table('assets')->where('id', $assetId)
                    ->where('expires_on <', $today)->whereIn('status', ['draft', 'active', 'rejected'])
                    ->update([
                        'status' => 'expired', 'expired_at' => $clock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                if ($this->db->affectedRows() === 0) {
                    continue;
                }

                $assignments = $this->db->table('device_assets')->select('id, device_id')
                    ->where('asset_id', $assetId)->get()->getResultArray();
                foreach ($assignments as $assignment) {
                    $deviceId = (int) $assignment['device_id'];
                    $affectedDevices[$deviceId] = true;
                    $this->db->table('device_assets')->where('id', $assignment['id'])
                        ->where('status !=', 'removal_pending')->update([
                            'status' => 'removal_pending',
                            'last_reported_at' => gmdate('Y-m-d H:i:s'),
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                    $assignmentCount += $this->db->affectedRows();
                }
                $scheduledDevices = $this->db->table('schedule_items si')->distinct()
                    ->select('st.device_id')->join('schedule_targets st', 'st.schedule_id = si.schedule_id')
                    ->where('si.asset_id', $assetId)->get()->getResultArray();
                foreach ($scheduledDevices as $scheduledDevice) {
                    $affectedDevices[(int) $scheduledDevice['device_id']] = true;
                }
                $expiredCount++;
            }

            foreach (array_keys($affectedDevices) as $deviceId) {
                $this->db->table('devices')->where('id', $deviceId)->update([
                    'asset_revision' => new RawSql('asset_revision + 1'),
                    'schedule_revision' => new RawSql('schedule_revision + 1'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            if ($this->db->transStatus() === false) throw new RuntimeException('Asset expiry transaction failed.');
            $this->db->transCommit();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }

        return [
            'expired' => $expiredCount, 'assignments' => $assignmentCount,
            'devices' => count($affectedDevices), 'date' => $today,
        ];
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }

    public function deadlineUtc(string $expiresOn): DateTimeImmutable
    {
        return (new DateTimeImmutable($expiresOn . ' 00:00:00', new DateTimeZone(self::TIMEZONE)))
            ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
    }
}
