<?php
declare(strict_types=1);

/**
 * Insert point_logs row and bump total_scores.
 * When $sourceRef is set, skips insert if this user already has a log for the same source_type + source_ref.
 *
 * @param mysqli $mysqli
 * @param positive-int $userId Student user id
 */
function award_points(
    mysqli $mysqli,
    int $userId,
    int $points,
    string $sourceType,
    ?string $sourceRef,
    ?string $detail
): void {
    if ($points === 0) {
        return;
    }

    if ($sourceRef !== null && $sourceRef !== '') {
        $dup = $mysqli->prepare(
            'SELECT id FROM point_logs WHERE user_id = ? AND source_type = ? AND source_ref = ? LIMIT 1'
        );
        $dup->bind_param('iss', $userId, $sourceType, $sourceRef);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            return;
        }
        $dup->close();
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO point_logs (user_id, points, source_type, source_ref, detail) VALUES (?,?,?,?,?)'
    );
    $stmt->bind_param('iisss', $userId, $points, $sourceType, $sourceRef, $detail);
    $stmt->execute();
    $stmt->close();

    $upd = $mysqli->prepare('UPDATE total_scores SET total_points = total_points + ? WHERE user_id = ?');
    $upd->bind_param('ii', $points, $userId);
    $upd->execute();
    if ($mysqli->affected_rows === 0) {
        $ins = $mysqli->prepare('INSERT INTO total_scores (user_id, total_points) VALUES (?, ?)');
        $ins->bind_param('ii', $userId, $points);
        $ins->execute();
        $ins->close();
    }
    $upd->close();
}

/**
 * Remove a participation award and deduct total_scores when a source is deleted.
 *
 * @return bool True when a matching point_logs row was removed.
 */
function revoke_points(
    mysqli $mysqli,
    int $userId,
    string $sourceType,
    ?string $sourceRef
): bool {
    if ($sourceRef === null || $sourceRef === '') {
        return false;
    }

    $sel = $mysqli->prepare(
        'SELECT id, points FROM point_logs
         WHERE user_id = ? AND source_type = ? AND source_ref = ?
         LIMIT 1'
    );
    $sel->bind_param('iss', $userId, $sourceType, $sourceRef);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    if (!$row) {
        return false;
    }

    $logId = (int) $row['id'];
    $points = (int) $row['points'];

    $del = $mysqli->prepare('DELETE FROM point_logs WHERE id = ?');
    $del->bind_param('i', $logId);
    $del->execute();
    $del->close();

    if ($points !== 0) {
        $upd = $mysqli->prepare(
            'UPDATE total_scores SET total_points = GREATEST(0, total_points - ?) WHERE user_id = ?'
        );
        $upd->bind_param('ii', $points, $userId);
        $upd->execute();
        $upd->close();
    }

    return true;
}

/**
 * Revoke participation points for every student tied to a source reference.
 */
function revoke_points_for_source(mysqli $mysqli, string $sourceType, string $sourceRef): void
{
    $sel = $mysqli->prepare(
        'SELECT user_id FROM point_logs WHERE source_type = ? AND source_ref = ?'
    );
    $sel->bind_param('ss', $sourceType, $sourceRef);
    $sel->execute();
    $res = $sel->get_result();
    $userIds = [];
    while ($row = $res->fetch_assoc()) {
        $userIds[] = (int) $row['user_id'];
    }
    $sel->close();

    foreach ($userIds as $uid) {
        revoke_points($mysqli, $uid, $sourceType, $sourceRef);
    }
}
