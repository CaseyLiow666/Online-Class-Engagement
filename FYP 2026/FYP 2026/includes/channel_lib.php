<?php
declare(strict_types=1);

/** Ensure a classroom has a default General channel; return its id. */
function classroom_ensure_general_channel(mysqli $mysqli, int $classroomId): int
{
    $st = $mysqli->prepare(
        'SELECT id FROM channels WHERE classroom_id = ? AND name = ? LIMIT 1'
    );
    $name = 'General';
    $st->bind_param('is', $classroomId, $name);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        return (int) $row['id'];
    }

    $ins = $mysqli->prepare('INSERT INTO channels (classroom_id, name) VALUES (?, ?)');
    $ins->bind_param('is', $classroomId, $name);
    $ins->execute();
    $id = (int) $mysqli->insert_id;
    $ins->close();
    return $id;
}

/** @return list<array{id:int,name:string}> */
function classroom_list_channels(mysqli $mysqli, int $classroomId): array
{
    classroom_ensure_general_channel($mysqli, $classroomId);
    $list = [];
    $st = $mysqli->prepare(
        'SELECT id, name FROM channels WHERE classroom_id = ? ORDER BY (name = ?) DESC, name ASC'
    );
    $general = 'General';
    $st->bind_param('is', $classroomId, $general);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $list[] = $row;
    }
    $st->close();
    return $list;
}

/** Post a system notification to the General channel. */
function classroom_post_notification(
    mysqli $mysqli,
    int $classroomId,
    int $actorUserId,
    string $body
): void {
    $channelId = classroom_ensure_general_channel($mysqli, $classroomId);
    $type = 'notification';
    $stmt = $mysqli->prepare(
        'INSERT INTO classroom_messages (classroom_id, channel_id, user_id, body, message_type) VALUES (?,?,?,?,?)'
    );
    $stmt->bind_param('iiiss', $classroomId, $channelId, $actorUserId, $body, $type);
    $stmt->execute();
    $stmt->close();
}
