<?php
declare(strict_types=1);

/**
 * @return non-empty-string 6-digit join code
 */
function classroom_generate_join_code(mysqli $mysqli): string
{
    for ($i = 0; $i < 25; $i++) {
        $code = (string) random_int(100000, 999999);
        $st = $mysqli->prepare('SELECT id FROM classrooms WHERE join_code = ? LIMIT 1');
        $st->bind_param('s', $code);
        $st->execute();
        if (!$st->get_result()->fetch_assoc()) {
            $st->close();
            return $code;
        }
        $st->close();
    }
    throw new RuntimeException('Could not allocate join code.');
}

function classroom_teacher_owns(mysqli $mysqli, int $teacherId, int $classroomId): bool
{
    $st = $mysqli->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ? LIMIT 1');
    $st->bind_param('ii', $classroomId, $teacherId);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

function classroom_student_member(mysqli $mysqli, int $studentId, int $classroomId): bool
{
    $st = $mysqli->prepare(
        'SELECT 1 FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1'
    );
    $st->bind_param('ii', $classroomId, $studentId);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

/** Whether a classroom is open (status = 1 on classrooms table). */
function classroom_is_open(mysqli $mysqli, int $classroomId): bool
{
    $st = $mysqli->prepare('SELECT status FROM classrooms WHERE id = ? LIMIT 1');
    $st->bind_param('i', $classroomId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return false;
    }
    return (int) $row['status'] === 1;
}

function classroom_card_icon(int $id): string
{
    $icons = ['💻', '📐', '🔬', '📚', '🎨', '🌍', '🧪', '📊', '✏️', '🎵', '📁', '⚗️'];
    return $icons[$id % count($icons)];
}

/** Google Classroom–style header stripe color for a class card. */
function classroom_card_color(int $id): string
{
    $colors = [
        '#1A73E8', /* Google Blue */
        '#1E8E3E', /* Emerald */
        '#F9AB00', /* Warm Amber */
        '#9334E6', /* Purple */
        '#E37400', /* Orange */
        '#0B8043', /* Forest */
        '#C5221F', /* Red */
        '#185ABC', /* Navy */
        '#00897B', /* Teal */
        '#5F6368', /* Slate */
    ];
    return $colors[$id % count($colors)];
}
