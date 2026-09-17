<?php

namespace Webkul\Accounting\Services\Peers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\WebRtcSessionStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\WebRtcSession;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;

/**
 * Brokers the WebRTC handshake for a browser-to-browser document transfer.
 *
 * This service moves SDP and ICE candidates ONLY. File bytes never pass
 * through it, and never through the server between the two parties -- that
 * is the entire justification for the feature, since DTLS means even a TURN
 * relay sees ciphertext. See
 * docs/superpowers/specs/2026-09-16-webrtc-browser-to-browser-transfer.md.
 *
 * Two things this deliberately does NOT do:
 *
 * - It never writes to the ledger. An inbound accounting record must be
 *   attributable to an authenticated peer, and a WebRTC counterparty is by
 *   construction just whoever held the session code. The prototype squared
 *   that circle by manufacturing an Active, paired Peer row to satisfy
 *   accounting_inbound_transmissions.peer_id; that foreign key is the
 *   control, not an obstacle.
 * - It never serves bytes to the receiver. The receiving side gets its file
 *   over the data channel or not at all.
 */
class WebRtcSignalingService
{
    /**
     * Crockford base32: no I, L, O or U, so a code read aloud or typed from
     * a screen cannot be transcribed into a different valid code.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 10 symbols x 5 bits = 50 bits, inside the column's 16 characters. */
    private const CODE_SYMBOLS = 10;

    /**
     * Open a signaling session for a record the actor is allowed to send.
     */
    public function createSession(User $actor, Model $transmittable, ?string $offerSdp = null, ?string $ipAddress = null): WebRtcSession
    {
        if (! config('webrtc.enabled', true)) {
            throw new RuntimeException('WebRTC direct transfer is currently disabled.');
        }

        $actorCompanyId = (int) $actor->default_company_id;

        if (! $actor->hasPermissionTo(AccountingPermissions::SendTransmissions)) {
            // company_id is a required FK on accounting_document_audits, so a
            // companyless account can't be attributed an audit row -- there's
            // nothing to attribute it to.
            if ($actorCompanyId > 0) {
                $this->audit($actorCompanyId, DocumentAuditAction::AccessDenied, $actor, [
                    'reason'             => 'missing_permission',
                    'transport'          => 'webrtc',
                    'transmittable_type' => $transmittable::class,
                    'transmittable_id'   => $transmittable->getKey(),
                ], $ipAddress);
            }

            throw new RuntimeException('You do not have permission to initiate transmissions.');
        }

        if ($actorCompanyId <= 0) {
            throw new RuntimeException('Your account has no default company, so it cannot send documents.');
        }

        // Read the company off the RECORD and compare. The prototype wrote
        // "$transmittable->company_id ?? $actor->default_company_id", which
        // let a record with a null company silently adopt the sender's.
        $recordCompanyId = $transmittable->company_id === null
            ? null
            : (int) $transmittable->company_id;

        if ($recordCompanyId === null || $recordCompanyId !== $actorCompanyId) {
            $this->audit($actorCompanyId, DocumentAuditAction::AccessDenied, $actor, [
                'reason'             => 'cross_company_transmittable',
                'transport'          => 'webrtc',
                'transmittable_type' => $transmittable::class,
                'transmittable_id'   => $transmittable->getKey(),
            ], $ipAddress);

            // Generic on purpose, matching resolveInvoiceForActor() and
            // DocumentService::find(): this branch is reachable not only via
            // the HTTP lookup above but from the Filament "Send to..." action
            // directly, and InvoiceResource does not company-scope its own
            // query -- "belongs to another company" would confirm to a
            // caller who reached this some other way that the id is real.
            throw new RuntimeException('That record could not be found.');
        }

        $session = WebRtcSession::create([
            'code'                => $this->generateUniqueCode(),
            'company_id'          => $actorCompanyId,
            'creator_id'          => $actor->id,
            'transmittable_type'  => $transmittable::class,
            'transmittable_id'    => $transmittable->getKey(),
            'status'              => WebRtcSessionStatus::Waiting,
            'offer_sdp'           => $offerSdp,
            'answer_sdp'          => null,
            'sender_candidates'   => [],
            'receiver_candidates' => [],
            'metadata'            => $this->buildMetadata($transmittable),
            'expires_at'          => now()->addMinutes((int) config('webrtc.session_ttl_minutes', 15)),
        ]);

        $this->audit($actorCompanyId, DocumentAuditAction::TransmissionSent, $actor, [
            'transport'          => 'webrtc',
            'session_id'         => $session->id,
            'transmittable_type' => $transmittable::class,
            'transmittable_id'   => $transmittable->getKey(),
        ], $ipAddress, $transmittable instanceof Document ? $transmittable->id : null);

        return $session;
    }

    /**
     * Resolve an invoice for a caller-supplied id, indistinguishable from a
     * missing one whether it doesn't exist or belongs to another company --
     * matching DocumentService::find()'s pattern, including the fact that
     * pattern audits the attempt server-side while keeping the client-facing
     * error identical either way, rather than trading one for the other.
     */
    public function resolveInvoiceForActor(User $actor, int $moveId): Move
    {
        $companyId = (int) $actor->default_company_id;

        $move = Move::query()->where('company_id', $companyId)->find($moveId);

        if ($move) {
            return $move;
        }

        $elsewhere = Move::query()->find($moveId);

        if ($elsewhere) {
            $this->audit((int) $elsewhere->company_id, DocumentAuditAction::AccessDenied, $actor, [
                'reason'             => 'cross_company_lookup',
                'transport'          => 'webrtc',
                'transmittable_type' => Move::class,
                'transmittable_id'   => $moveId,
            ]);
        }

        throw new ModelNotFoundException('Invoice not found.');
    }

    /**
     * Resolve a session that is still usable: unexpired AND not in a
     * terminal status. The prototype checked expiry only, so a completed
     * session stayed fully replayable for the rest of its TTL.
     */
    public function getActiveSession(string $code): ?WebRtcSession
    {
        $session = WebRtcSession::query()
            ->where('code', $this->normalizeCode($code))
            ->first();

        if (! $session || ! $session->isConnectable()) {
            return null;
        }

        return $session;
    }

    /**
     * Sender publishes its local SDP offer.
     */
    public function setOffer(User $actor, string $code, string $offerSdp): WebRtcSession
    {
        $session = $this->getActiveSession($code);

        if (! $session) {
            throw new RuntimeException('WebRTC session expired or not found.');
        }

        if ((int) $session->creator_id !== (int) $actor->id) {
            throw new RuntimeException('Only the sender can publish an offer for this session.');
        }

        $session->update(['offer_sdp' => $offerSdp]);

        return $session;
    }

    /**
     * Receiver joins and publishes its SDP answer.
     *
     * Single-shot: without this guard an attacker who guessed the code could
     * race the real recipient, post their own answer, and have the sender
     * stream the file to them instead.
     */
    public function submitAnswer(string $code, string $answerSdp): WebRtcSession
    {
        $session = $this->getActiveSession($code);

        if (! $session) {
            throw new RuntimeException('WebRTC session expired or not found.');
        }

        if ($session->answer_sdp !== null) {
            throw new RuntimeException('This session already has a recipient.');
        }

        $session->update([
            'answer_sdp'          => $answerSdp,
            'receiver_candidates' => [],
            'status'              => WebRtcSessionStatus::Connected,
        ]);

        return $session;
    }

    /**
     * Append an ICE candidate from either side.
     */
    public function addCandidate(string $code, string $role, array $candidate, ?string $clientIp = null): WebRtcSession
    {
        $session = $this->getActiveSession($code);

        if (! $session) {
            throw new RuntimeException('WebRTC session expired or not found.');
        }

        $candStr = $candidate['candidate'] ?? '';
        $extraCandidate = null;

        // Chrome obfuscates local IPs behind an mDNS .local hostname, which
        // the other browser cannot resolve across a LAN. Mirror the
        // candidate with the real address so same-network transfers connect.
        if (preg_match('/\b([0-9a-zA-Z-]+\.local)\b/', $candStr, $matches)) {
            $effectiveIp = ($clientIp === '127.0.0.1' || $clientIp === '::1' || empty($clientIp))
                ? gethostbyname(gethostname())
                : $clientIp;

            if ($effectiveIp && filter_var($effectiveIp, FILTER_VALIDATE_IP)) {
                $cloned = $candidate;
                $cloned['candidate'] = str_replace($matches[1], $effectiveIp, $candStr);
                $extraCandidate = $cloned;
            }
        }

        if ($role === 'sender') {
            $session->appendSenderCandidate($candidate);

            if ($extraCandidate) {
                $session->appendSenderCandidate($extraCandidate);
            }
        } elseif ($role === 'receiver') {
            $session->appendReceiverCandidate($candidate);

            if ($extraCandidate) {
                $session->appendReceiverCandidate($extraCandidate);
            }
        }

        return $session;
    }

    /**
     * Poll for the other side's SDP and ICE candidates.
     *
     * @return array{status: string, offer_sdp: ?string, answer_sdp: ?string, candidates: array, next_offset: int, metadata: ?array}
     */
    public function poll(string $code, string $forRole, int $candidateOffset = 0): array
    {
        $session = $this->getActiveSession($code);

        if (! $session) {
            return [
                'status'      => WebRtcSessionStatus::Expired->value,
                'offer_sdp'   => null,
                'answer_sdp'  => null,
                'candidates'  => [],
                'next_offset' => $candidateOffset,
                'metadata'    => null,
            ];
        }

        $peerCandidates = ($forRole === 'sender')
            ? ($session->receiver_candidates ?? [])
            : ($session->sender_candidates ?? []);

        return [
            'status'      => $session->status->value,
            'offer_sdp'   => $session->offer_sdp,
            'answer_sdp'  => $session->answer_sdp,
            'candidates'  => array_slice($peerCandidates, $candidateOffset),
            'next_offset' => count($peerCandidates),
            'metadata'    => $this->publicMetadata($session),
        ];
    }

    /**
     * Mark a finished transfer. Idempotent, and null-safe on an unknown code
     * -- the prototype declared a non-nullable return and returned null,
     * which turned an unknown code into an uncaught TypeError on an
     * unauthenticated route.
     */
    public function complete(string $code, ?string $ipAddress = null): ?WebRtcSession
    {
        $session = WebRtcSession::query()
            ->where('code', $this->normalizeCode($code))
            ->first();

        if (! $session || ! $session->isConnectable()) {
            return null;
        }

        $session->update([
            'status'       => WebRtcSessionStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->audit($session->company_id, DocumentAuditAction::TransmissionDelivered, null, [
            'transport'          => 'webrtc',
            'session_id'         => $session->id,
            'transmittable_type' => $session->transmittable_type,
            'transmittable_id'   => $session->transmittable_id,
        ], $ipAddress, $session->transmittable_type === Document::class ? (int) $session->transmittable_id : null);

        return $session;
    }

    /**
     * Gate the one endpoint that serves real bytes.
     *
     * Only the sender's own browser may read this, to feed the data channel.
     * The prototype checked nothing but the code and expiry, so any logged-in
     * user of any company could fetch any file whose code they held -- and
     * the client deliberately used it that way as a receiver-side fallback.
     */
    public function assertCanStream(User $actor, WebRtcSession $session, ?string $ipAddress = null): void
    {
        $denial = match (true) {
            (int) $session->creator_id !== (int) $actor->id                     => 'not_session_creator',
            (int) $session->company_id !== (int) $actor->default_company_id     => 'cross_company_session',
            ! $actor->hasPermissionTo(AccountingPermissions::SendTransmissions) => 'missing_permission',
            default                                                             => null,
        };

        if ($denial === null) {
            return;
        }

        $this->audit($session->company_id, DocumentAuditAction::AccessDenied, $actor, [
            'reason'     => $denial,
            'transport'  => 'webrtc',
            'session_id' => $session->id,
        ], $ipAddress, $session->transmittable_type === Document::class ? (int) $session->transmittable_id : null);

        throw new RuntimeException('You cannot read the contents of this transfer.');
    }

    /**
     * The subset of metadata safe to hand an unauthenticated caller.
     *
     * Enough to decide whether to accept the file, and nothing more. The
     * prototype returned the full formatted invoice here, so guessing a code
     * leaked an entire invoice without logging in at all.
     */
    public function publicMetadata(WebRtcSession $session): array
    {
        $metadata = $session->metadata ?? [];

        return [
            'type'      => $metadata['type'] ?? 'unknown',
            'title'     => $metadata['title'] ?? 'File',
            'filename'  => $metadata['filename'] ?? 'document.bin',
            'mime_type' => $metadata['mime_type'] ?? 'application/octet-stream',
            'file_size' => $metadata['file_size'] ?? null,
        ];
    }

    /**
     * Accept a code however the user typed it: lower case, spaces, missing
     * dashes, and the letters Crockford treats as digits.
     */
    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
        $code = strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);

        if (! str_starts_with($code, 'AUR')) {
            return $code;
        }

        $body = substr($code, 3);

        if (strlen($body) !== self::CODE_SYMBOLS) {
            return $code;
        }

        return 'AUR-'.substr($body, 0, 5).'-'.substr($body, 5);
    }

    /**
     * AUR-XXXXX-XXXXX -- 50 bits of entropy, grouped so it can be read out
     * loud. The prototype used 4 digits plus 2 uppercased characters, about
     * 23 bits, which was the sole gate on the anonymous endpoints.
     */
    private function generateUniqueCode(): string
    {
        do {
            $symbols = '';

            for ($i = 0; $i < self::CODE_SYMBOLS; $i++) {
                $symbols .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $code = 'AUR-'.substr($symbols, 0, 5).'-'.substr($symbols, 5);
        } while (WebRtcSession::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Metadata stored on the session. Display fields only -- see
     * publicMetadata(); nothing here may be sensitive, because the recipient
     * is not authenticated.
     */
    private function buildMetadata(Model $transmittable): array
    {
        if ($transmittable instanceof Move) {
            return [
                'type'      => 'invoice',
                'title'     => trim('Invoice '.(string) $transmittable->name),
                'filename'  => 'invoice-'.Str::slug((string) $transmittable->name).'.pdf',
                'mime_type' => 'application/pdf',
            ];
        }

        if ($transmittable instanceof Document) {
            $version = $transmittable->currentVersion;

            return [
                'type'      => 'document',
                'title'     => $transmittable->title,
                'filename'  => $version?->original_filename ?? 'document.bin',
                'mime_type' => $version?->mime_type ?? 'application/octet-stream',
                'file_size' => $version?->file_size ?? 0,
            ];
        }

        return [
            'type'  => 'unknown',
            'title' => 'File',
        ];
    }

    /**
     * Writes to the existing accounting_document_audits trail rather than a
     * parallel one. document_id is nullable there precisely so transport
     * events without a stored document (an invoice PDF) still land in it.
     */
    private function audit(int $companyId, DocumentAuditAction $action, ?User $actor, array $metadata, ?string $ipAddress = null, ?int $documentId = null): void
    {
        DocumentAudit::create([
            'company_id'  => $companyId,
            'document_id' => $documentId,
            'actor_id'    => $actor?->id,
            'action'      => $action,
            'metadata'    => $metadata ?: null,
            'ip_address'  => $ipAddress,
        ]);
    }
}
