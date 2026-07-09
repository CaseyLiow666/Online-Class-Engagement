<?php
declare(strict_types=1);

require_once __DIR__ . '/classroom_lib.php';

/** @return array<string, mixed>|null */
function classroom_message_fetch(mysqli $mysqli, int $messageId, int $classroomId): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT m.id, m.user_id, m.body, m.message_type, m.classroom_id
         FROM classroom_messages m
         WHERE m.id = ? AND m.classroom_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $messageId, $classroomId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function classroom_message_user_may_access(
    mysqli $mysqli,
    int $userId,
    string $role,
    int $classroomId
): bool {
    if ($role === 'teacher') {
        return classroom_teacher_owns($mysqli, $userId, $classroomId);
    }
    if ($role === 'student') {
        return classroom_student_member($mysqli, $userId, $classroomId);
    }

    return false;
}

/**
 * @return array{ok: true, body: string}|array{ok: false, error: string, code: int}
 */
function classroom_message_update(
    mysqli $mysqli,
    int $userId,
    string $role,
    int $messageId,
    int $classroomId,
    string $body
): array {
    $body = trim($body);
    if ($body === '' || strlen($body) > 4000) {
        return ['ok' => false, 'error' => 'Message must be 1–4000 characters.', 'code' => 400];
    }

    if (!classroom_message_user_may_access($mysqli, $userId, $role, $classroomId)) {
        return ['ok' => false, 'error' => 'Forbidden', 'code' => 403];
    }

    $row = classroom_message_fetch($mysqli, $messageId, $classroomId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Message not found', 'code' => 404];
    }

    $type = (string) $row['message_type'];

    if ($type === 'text') {
        if ((int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'You can only edit your own messages', 'code' => 403];
        }
    } elseif ($type === 'announcement') {
        if ($role !== 'teacher' || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
            return ['ok' => false, 'error' => 'Only the class teacher can edit announcements', 'code' => 403];
        }
    } else {
        return ['ok' => false, 'error' => 'This message cannot be edited', 'code' => 403];
    }

    $upd = $mysqli->prepare(
        'UPDATE classroom_messages SET body = ? WHERE id = ? AND classroom_id = ?'
    );
    $upd->bind_param('sii', $body, $messageId, $classroomId);
    $upd->execute();
    $upd->close();

    return ['ok' => true, 'body' => $body];
}

/**
 * @return array{ok: true}|array{ok: false, error: string, code: int}
 */
function classroom_message_delete(
    mysqli $mysqli,
    int $userId,
    string $role,
    int $messageId,
    int $classroomId
): array {
    if (!classroom_message_user_may_access($mysqli, $userId, $role, $classroomId)) {
        return ['ok' => false, 'error' => 'Forbidden', 'code' => 403];
    }

    $row = classroom_message_fetch($mysqli, $messageId, $classroomId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Message not found', 'code' => 404];
    }

    $type = (string) $row['message_type'];

    if ($type === 'text') {
        if ((int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'You can only delete your own messages', 'code' => 403];
        }
        $del = $mysqli->prepare(
            'DELETE FROM classroom_messages WHERE id = ? AND classroom_id = ? AND user_id = ? AND message_type = ?'
        );
        $textType = 'text';
        $del->bind_param('iiis', $messageId, $classroomId, $userId, $textType);
    } elseif ($type === 'announcement') {
        if ($role !== 'teacher' || !classroom_teacher_owns($mysqli, $userId, $classroomId)) {
            return ['ok' => false, 'error' => 'Only the class teacher can delete announcements', 'code' => 403];
        }
        $del = $mysqli->prepare(
            'DELETE FROM classroom_messages WHERE id = ? AND classroom_id = ? AND message_type = ?'
        );
        $annType = 'announcement';
        $del->bind_param('iis', $messageId, $classroomId, $annType);
    } else {
        return ['ok' => false, 'error' => 'This message cannot be deleted', 'code' => 403];
    }

    $del->execute();
    $del->close();

    return ['ok' => true];
}
