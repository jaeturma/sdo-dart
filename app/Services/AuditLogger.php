<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SignatureAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        ?User $user,
        ?Document $document,
        string $action,
        ?Request $request = null,
        array $metadata = [],
    ): SignatureAuditLog {
        $prevHash = SignatureAuditLog::latest('id')->value('row_hash') ?? '';

        $userId     = $user?->id;
        $documentId = $document?->id;
        $ipAddress  = $request?->ip();
        $userAgent  = $request?->userAgent();
        $metaJson   = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

        // Hash covers every auditable field plus the chain link
        $rowHash = hash('sha256', implode('|', [
            '', // id unknown at compute time; appended via update below
            (string) $userId,
            (string) $documentId,
            $action,
            (string) $ipAddress,
            (string) $userAgent,
            (string) $metaJson,
            $prevHash,
        ]));

        $log = SignatureAuditLog::create([
            'user_id'     => $userId,
            'document_id' => $documentId,
            'action'      => $action,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'metadata'    => $metadata ?: null,
            'prev_hash'   => $prevHash,
            'row_hash'    => $rowHash,
        ]);

        // Re-compute with the real id now that the row exists
        $finalHash = hash('sha256', implode('|', [
            (string) $log->id,
            (string) $userId,
            (string) $documentId,
            $action,
            (string) $ipAddress,
            (string) $userAgent,
            (string) $metaJson,
            $prevHash,
        ]));

        // Direct DB update to avoid triggering model events that might re-hash
        \Illuminate\Support\Facades\DB::table('signature_audit_logs')
            ->where('id', $log->id)
            ->update(['row_hash' => $finalHash]);

        $log->row_hash = $finalHash;

        return $log;
    }
}
