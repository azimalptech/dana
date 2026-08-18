<?php

declare(strict_types=1);

namespace Dana\Domain\Notifications;

use Dana\Domain\Models\Classroom;
use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * FR-10: admin → their own centre, teacher → their own classrooms
 * (FR-12.12). The superadmin does not send announcements — announcements
 * belong to whoever runs the centre.
 *
 * Deliberately NOT fanned out into one receipt row per recipient. At the
 * 100,000-student target (NFR-1) a centre-wide announcement would write a
 * row per member and take seconds. Instead a notification stores who it
 * was addressed to, the inbox is a scoped query, and
 * `notification_receipts` records only the read marks that actually
 * happen — which is a small fraction.
 */
final class NotificationService
{
    public function __construct(
        private readonly FcmSender $push,
    ) {
    }

    public function send(Scope $scope, array $input): array
    {
        // FR-10.1: the superadmin is deliberately absent. Announcements
        // are a centre's business, so there is no all-users broadcast.
        $scope->requireRole(
            User::ROLE_ADMIN,
            User::ROLE_TEACHER,
        );

        $titleTk = trim((string) ($input['title_tk'] ?? ''));
        $titleRu = trim((string) ($input['title_ru'] ?? ''));
        $bodyTk = trim((string) ($input['body_tk'] ?? ''));
        $bodyRu = trim((string) ($input['body_ru'] ?? ''));

        // Both languages are required: a student whose interface is
        // Russian must not receive an empty message because the sender
        // only filled the Turkmen box.
        if ($titleTk === '' || $titleRu === '' || $bodyTk === '' || $bodyRu === '') {
            throw ApiException::validation(
                'Iki dilde-de at we tekst gerek.',
                'Заголовок и текст нужны на обоих языках.'
            );
        }

        [$scopeName, $centerId, $classroomId] = $this->resolveTarget($scope, $input);

        $now = date('Y-m-d H:i:s');

        $id = Capsule::table('notifications')->insertGetId([
            'sender_id'    => $scope->userId,
            'scope'        => $scopeName,
            'center_id'    => $centerId,
            'classroom_id' => $classroomId,
            'title_tk'     => mb_substr($titleTk, 0, 160),
            'title_ru'     => mb_substr($titleRu, 0, 160),
            'body_tk'      => mb_substr($bodyTk, 0, 1000),
            'body_ru'      => mb_substr($bodyRu, 0, 1000),
            'sent_at'      => $now,
            'created_at'   => $now,
        ]);

        Capsule::table('audit_log')->insert([
            'actor_id'    => $scope->userId,
            'action'      => 'notification.sent',
            'entity_type' => 'notification',
            'entity_id'   => $id,
            'meta'        => json_encode([
                'scope'        => $scopeName,
                'center_id'    => $centerId,
                'classroom_id' => $classroomId,
            ]),
            'created_at'  => $now,
        ]);

        $recipients = $this->recipientCount($scopeName, $centerId, $classroomId);

        // The inbox row above is already written, so delivery failing
        // here costs timeliness, not the message (FR-10.3).
        $delivery = $this->pushTo($id, $scopeName, $centerId, $classroomId, $titleRu, $bodyRu);

        return [
            'id'         => $id,
            'scope'      => $scopeName,
            'recipients' => $recipients,
            'push'       => $delivery,
        ];
    }

    /**
     * Audiences up to this many devices are pushed inline; larger ones
     * are handed to the background worker. One classroom fits inline;
     * "all users" at the 100k target must never block an HTTP request.
     */
    private const INLINE_PUSH_LIMIT = 100;

    /**
     * @return array{configured: bool, sent: int, failed: int, queued: bool}
     */
    private function pushTo(
        int $notificationId,
        string $scopeName,
        ?int $centerId,
        ?int $classroomId,
        string $title,
        string $body,
    ): array {
        $none = ['configured' => false, 'sent' => 0, 'failed' => 0, 'queued' => false];

        if (!$this->push->isConfigured()) {
            return $none;
        }

        $tokens = self::deviceTokensFor($scopeName, $centerId, $classroomId);

        if ($tokens === []) {
            return ['configured' => true, 'sent' => 0, 'failed' => 0, 'queued' => false];
        }

        if (count($tokens) > self::INLINE_PUSH_LIMIT) {
            Capsule::table('notifications')->where('id', $notificationId)
                ->update(['push_status' => 'pending']);

            return ['configured' => true, 'sent' => 0, 'failed' => 0, 'queued' => true];
        }

        $result = $this->push->send($tokens, $title, $body, [
            'notification_id' => (string) $notificationId,
        ]);

        self::pruneTokens($result['invalid']);

        Capsule::table('notifications')->where('id', $notificationId)->update([
            'push_status' => 'done',
            'push_sent'   => $result['sent'],
            'push_failed' => $result['failed'],
        ]);

        return [
            'configured' => true,
            'sent'       => $result['sent'],
            'failed'     => $result['failed'],
            'queued'     => false,
        ];
    }

    /**
     * Shared with the background worker, which delivers what was too
     * large to send inline.
     *
     * @return list<string>
     */
    public static function deviceTokensFor(string $scopeName, ?int $centerId, ?int $classroomId): array
    {
        return Capsule::table('device_tokens')
            ->join('users', 'users.id', '=', 'device_tokens.user_id')
            ->where('users.is_active', 1)
            ->when($scopeName === 'center', fn ($q) => $q->where('users.center_id', $centerId))
            ->when($scopeName === 'classroom', fn ($q) => $q->where('users.classroom_id', $classroomId))
            ->pluck('device_tokens.token')
            ->all();
    }

    /** Dead tokens are pruned, not retried on every future announcement. */
    public static function pruneTokens(array $invalid): void
    {
        if ($invalid !== []) {
            Capsule::table('device_tokens')->whereIn('token', $invalid)->delete();
        }
    }

    /** Called by the app whenever FCM issues or rotates a token. */
    public function registerDevice(int $userId, string $token, string $platform): void
    {
        if ($token === '' || !in_array($platform, ['ios', 'android'], true)) {
            throw ApiException::validation('Enjam belgisi nädogry.', 'Некорректный токен устройства.');
        }

        // A token identifies a device, and devices get handed between
        // students. Re-registering moves it to whoever holds it now
        // rather than pushing one student's messages to another.
        Capsule::table('device_tokens')->updateOrInsert(
            ['token' => $token],
            ['user_id' => $userId, 'platform' => $platform, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    /** @return array{0: string, 1: ?int, 2: ?int} */
    private function resolveTarget(Scope $scope, array $input): array
    {
        $requested = (string) ($input['scope'] ?? '');

        // Admin may address their own centre, and only their own
        // (FR-10.2). The centre comes from the token, never the body, so
        // there is no id an admin could swap to reach another centre.
        if ($scope->isAdmin() && $requested === 'center') {
            if ($scope->centerId === null) {
                throw ApiException::validation('Merkez saýlanmady.', 'Центр не выбран.');
            }

            return ['center', $scope->centerId, null];
        }

        if ($requested === 'classroom') {
            $classroomId = (int) ($input['classroom_id'] ?? 0);

            // Scoped lookup — a teacher cannot notify someone else's class.
            $classroom = $scope->applyToClassrooms(Classroom::query())
                ->where('id', $classroomId)
                ->first();

            if ($classroom === null) {
                throw ApiException::notFound();
            }

            return ['classroom', (int) $classroom->center_id, (int) $classroom->id];
        }

        throw ApiException::forbidden();
    }

    /** The inbox: every notification addressed to this user. */
    public function inboxFor(User $user, int $limit = 50): array
    {
        $rows = Capsule::table('notifications as n')
            ->leftJoin('notification_receipts as r', function ($join) use ($user): void {
                $join->on('r.notification_id', '=', 'n.id')
                    ->where('r.user_id', '=', $user->id);
            })
            ->whereNotNull('n.sent_at')
            // Announcements sent before the account existed are not this
            // student's history and would be confusing on first login.
            ->where('n.created_at', '>=', $user->created_at)
            ->where(function ($q) use ($user): void {
                $q->where('n.scope', 'all')
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('n.scope', 'center')->where('n.center_id', $user->center_id);
                    })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('n.scope', 'classroom')
                            ->where('n.classroom_id', $user->classroom_id);
                    });
            })
            ->orderByDesc('n.sent_at')
            ->limit($limit)
            ->select([
                'n.id', 'n.title_tk', 'n.title_ru', 'n.body_tk', 'n.body_ru',
                'n.sent_at', 'r.read_at',
            ])
            ->get();

        return [
            'items' => $rows->map(fn ($row): array => [
                'id'       => (int) $row->id,
                'title_tk' => $row->title_tk,
                'title_ru' => $row->title_ru,
                'body_tk'  => $row->body_tk,
                'body_ru'  => $row->body_ru,
                'sent_at'  => $row->sent_at,
                'read'     => $row->read_at !== null,
            ])->all(),
            'unread' => $rows->where('read_at', null)->count(),
        ];
    }

    public function markRead(User $user, int $notificationId): void
    {
        // Only mark what the user is actually addressed by, so an
        // arbitrary id cannot probe which ids exist. Checked with a
        // direct query — the previous scan of the first 200 inbox items
        // made anything older than that impossible to mark read.
        $addressed = Capsule::table('notifications')
            ->where('id', $notificationId)
            ->whereNotNull('sent_at')
            ->where('created_at', '>=', $user->created_at)
            ->where(function ($q) use ($user): void {
                $q->where('scope', 'all')
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('scope', 'center')->where('center_id', $user->center_id);
                    })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('scope', 'classroom')->where('classroom_id', $user->classroom_id);
                    });
            })
            ->exists();

        if (!$addressed) {
            throw ApiException::notFound();
        }

        Capsule::table('notification_receipts')->updateOrInsert(
            ['notification_id' => $notificationId, 'user_id' => $user->id],
            ['read_at' => date('Y-m-d H:i:s')]
        );
    }

    private function recipientCount(string $scope, ?int $centerId, ?int $classroomId): int
    {
        $query = Capsule::table('users')->where('is_active', 1);

        // No 'all' arm: nothing can send one any more. The inbox query
        // still understands scope='all' because rows sent before the rule
        // changed are still addressed to the students who received them.
        return match ($scope) {
            'center'    => $query->where('center_id', $centerId)->count(),
            'classroom' => $query->where('classroom_id', $classroomId)->count(),
            default     => 0,
        };
    }
}
