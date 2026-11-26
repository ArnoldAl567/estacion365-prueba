<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class OperationsAudit
{
    public static function record(?int $user, string $action, string $entity, int|string $id, array $details = []): void
    {
        DB::table('operations_audit')->insert([
            'user_id' => $user, 'action' => $action, 'entity' => $entity, 'entity_id' => (string) $id,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
