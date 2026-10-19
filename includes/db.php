<?php
declare(strict_types=1);

/** اتصال PDO واحد لكل طلب. جميع الاستعلامات تستخدم Prepared Statements. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = $GLOBALS['ECM_CONFIG']['db'] ?? null;
    if (!$c) {
        throw new RuntimeException('ملف الإعدادات غير موجود أو غير صالح.');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    // توحيد المنطقة الزمنية بين PHP وقاعدة البيانات
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function ident(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
        throw new InvalidArgumentException('اسم حقل غير صالح');
    }
    return '`' . $name . '`';
}

function insert(string $table, array $data): int
{
    $cols = array_map('ident', array_keys($data));
    $sql  = 'INSERT INTO ' . ident($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(',', array_map(fn($c) => ident($c) . '=?', array_keys($data)));
    $st  = q('UPDATE ' . ident($table) . " SET $set WHERE $where", array_merge(array_values($data), $whereParams));
    return $st->rowCount();
}

/** تنفيذ مجموعة عمليات داخل معاملة؛ أي استثناء يعيد التراجع الكامل. */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** رقم متسلسل فريد (آمن مع التزامن) مثل ST-000012 */
function next_seq(string $name, string $prefix, int $pad = 6): string
{
    q('INSERT INTO sequences (name, `last_value`) VALUES (?, 0) ON DUPLICATE KEY UPDATE name = name', [$name]);
    q('UPDATE sequences SET `last_value` = LAST_INSERT_ID(`last_value` + 1) WHERE name = ?', [$name]);
    $n = (int)val('SELECT LAST_INSERT_ID()');
    return $prefix . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

function is_duplicate_error(Throwable $e): bool
{
    return $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062;
}
