<?php
declare(strict_types=1);

const QR_PREFIX = 'ECM1.';

function new_qr_token(): string
{
    return bin2hex(random_bytes(24)); // 48 خانة سداسية
}

/** محتوى QR: بادئة ثابتة + معرّف عشوائي. لا يحوي كلمة مرور ولا بيانات حساسة. */
function qr_payload(string $token): string
{
    return QR_PREFIX . $token;
}

function parse_qr_payload(string $raw): ?string
{
    $raw = trim($raw);
    if (strpos($raw, QR_PREFIX) === 0 && preg_match('/^[a-f0-9]{48}$/', substr($raw, strlen(QR_PREFIX)))) {
        return substr($raw, strlen(QR_PREFIX));
    }
    return null;
}

// =====================================================================  الحصص
/** هل يتعارض المدرس/القاعة مع حصة موجودة؟ يعيد قائمة بالتعارضات (نصوص) */
function session_conflicts(int $teacherId, ?string $room, string $date, string $start, string $end, ?int $excludeId = null, ?int $groupId = null): array
{
    $out = [];
    $ex = $excludeId ?: 0;
    $r = rows("SELECT s.id, g.name FROM class_sessions s JOIN class_groups g ON g.id = s.group_id
        WHERE s.teacher_id = ? AND s.session_date = ? AND s.status <> 'cancelled' AND s.id <> ? AND s.start_time < ? AND s.end_time > ?", [$teacherId, $date, $ex, $end, $start]);
    foreach ($r as $x) {
        $out[] = 'المدرس لديه حصة أخرى في نفس الوقت: ' . $x['name'];
    }
    if ($room !== null && trim($room) !== '') {
        $r = rows("SELECT s.id, g.name FROM class_sessions s JOIN class_groups g ON g.id = s.group_id
            WHERE s.room = ? AND s.session_date = ? AND s.status <> 'cancelled' AND s.id <> ? AND s.start_time < ? AND s.end_time > ?", [$room, $date, $ex, $end, $start]);
        foreach ($r as $x) {
            $out[] = 'القاعة محجوزة في نفس الوقت: ' . $x['name'];
        }
    }
    return $out;
}

/** تعارض جدول أسبوعي: مدرس/قاعة في نفس اليوم والوقت لمجموعات نشطة أخرى */
function schedule_conflicts(int $groupId, int $teacherId, ?string $room, int $weekday, string $start, string $end, ?int $excludeScheduleId = null): array
{
    $out = [];
    $ex = $excludeScheduleId ?: 0;
    $base = "SELECT sc.id, g.name FROM schedules sc JOIN class_groups g ON g.id = sc.group_id
        WHERE sc.is_active = 1 AND g.status = 'active' AND sc.weekday = ? AND sc.id <> ? AND sc.group_id <> ? AND sc.start_time < ? AND sc.end_time > ?";
    foreach (rows($base . ' AND g.teacher_id = ?', [$weekday, $ex, $groupId, $end, $start, $teacherId]) as $x) {
        $out[] = 'المدرس مرتبط بمجموعة أخرى في نفس الوقت: ' . $x['name'];
    }
    if ($room !== null && trim($room) !== '') {
        foreach (rows($base . ' AND COALESCE(NULLIF(sc.room, \'\'), g.room) = ?', [$weekday, $ex, $groupId, $end, $start, $room]) as $x) {
            $out[] = 'القاعة مستخدمة في نفس الوقت: ' . $x['name'];
        }
    }
    return $out;
}

/** توليد الحصص الفعلية من الجداول الأسبوعية (آمن لإعادة التشغيل). لا يُنشأ أي حضور. */
function generate_sessions(?array $groupIds, string $from, string $to, int $userId): array
{
    if (!valid_date($from) || !valid_date($to) || $to < $from) {
        throw new RuntimeException('نطاق التاريخ غير صالح.');
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 120) {
        throw new RuntimeException('الحد الأقصى لتوليد الحصص دفعة واحدة 120 يوماً.');
    }
    $sql = "SELECT sc.*, g.teacher_id, g.room AS group_room, g.start_date, g.end_date FROM schedules sc JOIN class_groups g ON g.id = sc.group_id
            WHERE sc.is_active = 1 AND g.status = 'active'";
    $p = [];
    if ($groupIds !== null) {
        if (!$groupIds) {
            return ['created' => 0, 'existing' => 0];
        }
        $sql .= ' AND g.id IN (' . implode(',', array_fill(0, count($groupIds), '?')) . ')';
        $p = $groupIds;
    }
    $created = 0;
    $existing = 0;
    $scheds = rows($sql, $p);
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $wd = (int)date('N', $t);
        foreach ($scheds as $sc) {
            if ((int)$sc['weekday'] !== $wd) {
                continue;
            }
            if (($sc['start_date'] && $d < $sc['start_date']) || ($sc['end_date'] && $d > $sc['end_date'])) {
                continue;
            }
            $room = trim((string)$sc['room']) !== '' ? $sc['room'] : $sc['group_room'];
            $n = q('INSERT IGNORE INTO class_sessions (group_id, schedule_id, session_date, start_time, end_time, teacher_id, room, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$sc['group_id'], $sc['id'], $d, $sc['start_time'], $sc['end_time'], $sc['teacher_id'], $room, 'scheduled', $userId])->rowCount();
            $n ? $created++ : $existing++;
        }
    }
    audit('sessions_generated', 'class_session', null, ['from' => $from, 'to' => $to, 'created' => $created]);
    return ['created' => $created, 'existing' => $existing];
}

function session_info(int $sessionId): ?array
{
    return row('SELECT s.*, g.name AS group_name, g.code AS group_code, g.status AS group_status, sub.name AS subject_name, t.full_name AS teacher_name
        FROM class_sessions s JOIN class_groups g ON g.id = s.group_id JOIN subjects sub ON sub.id = g.subject_id JOIN teachers t ON t.id = s.teacher_id WHERE s.id = ?', [$sessionId]);
}

function session_label(array $s): string
{
    return $s['group_name'] . ' — ' . fdate($s['session_date']) . ' ' . ftime($s['start_time']) . '–' . ftime($s['end_time']);
}

// =====================================================================  الحضور اليدوي
/**
 * حفظ كشف الحضور دفعة واحدة. لا يُنشأ سجل إلا لطالب مسجّل في مجموعة الحصة.
 * @param array<int,string> $statuses student_id => status
 * @param array<int,string> $notes student_id => note
 * @return array{saved:int,changed:int,denied:int}
 */
function save_attendance_batch(int $sessionId, array $statuses, array $notes, int $userId, bool $canEdit): array
{
    return tx(function () use ($sessionId, $statuses, $notes, $userId, $canEdit) {
        $s = row('SELECT * FROM class_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
        if (!$s) {
            throw new RuntimeException('الحصة غير موجودة.');
        }
        if ($s['status'] === 'cancelled') {
            throw new RuntimeException('الحصة ملغاة ولا يمكن تسجيل حضورها.');
        }
        if ($s['session_date'] > today()) {
            throw new RuntimeException('لا يمكن تسجيل حضور حصة مستقبلية.');
        }
        $enrolled = array_column(rows("SELECT student_id FROM group_students WHERE group_id = ? AND status = 'active'", [$s['group_id']]), 'student_id');
        $enrolled = array_map('intval', $enrolled);
        $existing = [];
        foreach (rows('SELECT id, student_id, status FROM attendance WHERE session_id = ?', [$sessionId]) as $a) {
            $existing[(int)$a['student_id']] = $a;
        }
        $valid = array_keys(attendance_statuses());
        $saved = 0;
        $changed = 0;
        $denied = 0;
        foreach ($statuses as $sid => $st) {
            $sid = (int)$sid;
            if (!in_array($sid, $enrolled, true) || !in_array($st, $valid, true)) {
                continue;
            }
            $note = isset($notes[$sid]) && trim((string)$notes[$sid]) !== '' ? mb_substr(trim((string)$notes[$sid]), 0, 255) : null;
            if (isset($existing[$sid])) {
                if ($existing[$sid]['status'] === $st) {
                    q('UPDATE attendance SET notes = ? WHERE id = ?', [$note, $existing[$sid]['id']]);
                    continue;
                }
                if (!$canEdit) {
                    $denied++;
                    continue;
                }
                q('UPDATE attendance SET status = ?, notes = ?, marked_by = ?, marked_at = NOW() WHERE id = ?', [$st, $note, $userId, $existing[$sid]['id']]);
                audit('attendance_edited', 'attendance', (int)$existing[$sid]['id'], ['session' => $sessionId, 'student' => $sid, 'from' => $existing[$sid]['status'], 'to' => $st]);
                $changed++;
            } else {
                q('INSERT INTO attendance (session_id, student_id, status, method, marked_by, notes) VALUES (?,?,?,?,?,?)', [$sessionId, $sid, $st, 'manual', $userId, $note]);
                $saved++;
            }
        }
        if ($s['status'] === 'scheduled' && ($saved + $changed) > 0) {
            q("UPDATE class_sessions SET status = 'held' WHERE id = ?", [$sessionId]);
        }
        audit('attendance_saved', 'class_session', $sessionId, ['saved' => $saved, 'changed' => $changed]);
        return ['saved' => $saved, 'changed' => $changed, 'denied' => $denied];
    });
}

// =====================================================================  حضور QR
/**
 * معالجة مسح QR أو إدخال رقم الطالب.
 * @return array{ok:bool,code:string,message:string,student?:array,fin?:array}
 * code: recorded|duplicate|invalid|revoked|inactive|not_enrolled|session_closed|session_invalid
 */
function scan_attendance(int $sessionId, string $raw, int $userId, bool $byNumber = false): array
{
    $s = session_info($sessionId);
    if (!$s) {
        return ['ok' => false, 'code' => 'session_invalid', 'message' => 'الحصة غير موجودة.'];
    }
    if ($s['status'] === 'cancelled' || $s['session_date'] !== today()) {
        return ['ok' => false, 'code' => 'session_closed', 'message' => 'المسح متاح لحصص اليوم غير الملغاة فقط.'];
    }
    // حماية من التخمين العشوائي: أكثر من 15 رمزاً غير صالح خلال 5 دقائق لنفس المستخدم يوقف المسح مؤقتاً
    $bad = (int)val("SELECT COUNT(*) FROM audit_logs WHERE action = 'qr_invalid' AND user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)", [$userId]);
    if ($bad >= 15) {
        return ['ok' => false, 'code' => 'invalid', 'message' => 'محاولات مسح غير صالحة كثيرة. انتظر دقائق ثم أعد المحاولة.'];
    }
    $student = null;
    if ($byNumber) {
        $student = row('SELECT * FROM students WHERE student_no = ?', [strtoupper(trim($raw))]);
    } else {
        $tok = parse_qr_payload($raw);
        if ($tok) {
            $student = row('SELECT * FROM students WHERE qr_token = ?', [$tok]);
        }
    }
    if (!$student) {
        audit('qr_invalid', 'class_session', $sessionId, ['len' => strlen($raw)]);
        return ['ok' => false, 'code' => 'invalid', 'message' => $byNumber ? 'رقم الطالب غير موجود.' : 'رمز QR غير صالح أو غير معروف.'];
    }
    $info = ['id' => (int)$student['id'], 'name' => $student['full_name'], 'no' => $student['student_no'], 'status' => $student['status']];
    if (!$byNumber && $student['qr_status'] !== 'active') {
        audit('qr_revoked_scan', 'student', (int)$student['id'], ['session' => $sessionId]);
        return ['ok' => false, 'code' => 'revoked', 'message' => 'رمز QR لهذا الطالب موقوف أو أُعيد إصداره. استخدم البطاقة الحديثة.', 'student' => $info];
    }
    if ($student['status'] !== 'active') {
        return ['ok' => false, 'code' => 'inactive', 'message' => 'الطالب ' . (student_statuses()[$student['status']] ?? '') . ' ولا يمكن تسجيل حضوره.', 'student' => $info];
    }
    $enr = row("SELECT id FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'", [$s['group_id'], $student['id']]);
    if (!$enr) {
        audit('qr_not_enrolled', 'student', (int)$student['id'], ['session' => $sessionId]);
        return ['ok' => false, 'code' => 'not_enrolled', 'message' => 'الطالب غير مسجّل في مجموعة هذه الحصة.', 'student' => $info];
    }
    $fin = subscription_state((int)$student['id'], (int)$s['group_id']);
    $finOut = ['state' => $fin['state'], 'label' => subscription_state_label($fin['state'])[0], 'due' => cents_str($fin['due']), 'overdue' => cents_str($fin['overdue'])];
    try {
        tx(function () use ($sessionId, $student, $userId, $s) {
            q('INSERT INTO attendance (session_id, student_id, status, method, marked_by) VALUES (?,?,?,?,?)', [$sessionId, $student['id'], 'present', 'qr', $userId]);
            q("UPDATE class_sessions SET status = 'held' WHERE id = ? AND status = 'scheduled'", [$sessionId]);
        });
    } catch (PDOException $e) {
        if (!is_duplicate_error($e)) {
            throw $e;
        }
        audit('attendance_duplicate_scan', 'class_session', $sessionId, ['student' => (int)$student['id']]);
        q('INSERT IGNORE INTO notifications (type, severity, title, body, entity_type, entity_id, link, dedupe_key) VALUES (?,?,?,?,?,?,?,?)',
            ['duplicate_scan', 'warning', 'محاولة تسجيل حضور مكرر', $student['full_name'] . ' — ' . session_label($s), 'class_session', $sessionId,
                'modules/attendance/mark.php?session_id=' . $sessionId, 'dupscan:' . $sessionId . ':' . $student['id']]);
        return ['ok' => false, 'code' => 'duplicate', 'message' => 'تم تسجيل حضور هذا الطالب في الحصة مسبقاً.', 'student' => $info, 'fin' => $finOut];
    }
    audit('attendance_qr', 'class_session', $sessionId, ['student' => (int)$student['id'], 'by_number' => $byNumber]);
    return ['ok' => true, 'code' => 'recorded', 'message' => 'تم تسجيل الحضور.', 'student' => $info, 'fin' => $finOut];
}

/** نسبة الحضور (حاضر + متأخر) من إجمالي السجلات */
function attendance_rate(int $present, int $total): string
{
    return $total > 0 ? round($present * 100 / $total, 1) . '%' : '—';
}

// =====================================================================  التسجيل في المجموعات
/** تسجيل طالب في مجموعة (يتحقق من السعة وحالة الطالب/المجموعة). اختيارياً ينشئ اشتراكاً — دون أي دفعة. */
function enroll_student(int $studentId, int $groupId, bool $createSub, int $userId): void
{
    tx(function () use ($studentId, $groupId, $createSub, $userId) {
        $g = row('SELECT g.*, ps.mode, ps.monthly_fee, ps.session_price FROM class_groups g LEFT JOIN group_payment_settings ps ON ps.group_id = g.id WHERE g.id = ? FOR UPDATE', [$groupId]);
        $s = row('SELECT id, status FROM students WHERE id = ?', [$studentId]);
        if (!$g || !$s) {
            throw new RuntimeException('الطالب أو المجموعة غير موجود.');
        }
        if ($s['status'] !== 'active') {
            throw new RuntimeException('لا يمكن تسجيل طالب غير نشط.');
        }
        if ($g['status'] === 'finished') {
            throw new RuntimeException('المجموعة منتهية.');
        }
        $cur = row('SELECT id, status FROM group_students WHERE group_id = ? AND student_id = ?', [$groupId, $studentId]);
        if ($cur && $cur['status'] === 'active') {
            throw new RuntimeException('الطالب مسجّل في هذه المجموعة بالفعل.');
        }
        $n = (int)val("SELECT COUNT(*) FROM group_students WHERE group_id = ? AND status = 'active'", [$groupId]);
        if ($n >= (int)$g['max_students']) {
            throw new RuntimeException('المجموعة ممتلئة (الحد الأقصى ' . (int)$g['max_students'] . ').');
        }
        if ($cur) {
            q("UPDATE group_students SET status = 'active', joined_at = CURDATE(), left_at = NULL WHERE id = ?", [$cur['id']]);
        } else {
            insert('group_students', ['group_id' => $groupId, 'student_id' => $studentId, 'status' => 'active', 'joined_at' => today()]);
        }
        if ($createSub) {
            if (!$g['mode']) {
                throw new RuntimeException('حدد نظام الدفع للمجموعة أولاً.');
            }
            if (!row("SELECT id FROM subscriptions WHERE student_id = ? AND group_id = ? AND status = 'active'", [$studentId, $groupId])) {
                insert('subscriptions', ['student_id' => $studentId, 'group_id' => $groupId, 'mode' => $g['mode'], 'unit_price' => $g['mode'] === 'monthly' ? $g['monthly_fee'] : $g['session_price'],
                    'discount_amount' => '0.00', 'start_date' => today(), 'status' => 'active', 'created_by' => $userId]);
            }
        }
        audit('student_enrolled', 'group', $groupId, ['student_id' => $studentId, 'subscription' => $createSub]);
    });
}

function unenroll_student(int $studentId, int $groupId): void
{
    q("UPDATE group_students SET status = 'left', left_at = CURDATE() WHERE group_id = ? AND student_id = ? AND status = 'active'", [$groupId, $studentId]);
    audit('student_unenrolled', 'group', $groupId, ['student_id' => $studentId]);
}
