<?php

namespace App\Operations;

use App\Domain\AccountDeletion\JournalUnavailable;
use Illuminate\Support\Facades\DB;

/** Additional write detection on the dedicated MySQL target; external writer fencing remains mandatory. */
final class RestoreWriteFence
{
    public function fingerprint(): string
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql') {
            throw new JournalUnavailable;
        }
        $counters = [];
        foreach ($db->select('SHOW GLOBAL STATUS') as $row) {
            if (! in_array($row->Variable_name, ['Com_change_db', 'Com_reset_connection'], true)
                && preg_match('/^Com_(insert|delete|update|replace|load|create|drop|alter|rename|truncate|change|grant|revoke|flush|repair|optimize|commit|rollback|lock|unlock|reset|shutdown|install|uninstall|xa_)/i', $row->Variable_name)) {
                $counters[$row->Variable_name] = $row->Value;
            }
        }
        if (! isset($counters['Com_insert'], $counters['Com_update'], $counters['Com_delete'])) {
            throw new JournalUnavailable;
        }
        ksort($counters);

        return hash('sha256', json_encode([$db->selectOne('SELECT @@server_uuid AS identity')->identity, $counters], JSON_THROW_ON_ERROR));
    }
}
