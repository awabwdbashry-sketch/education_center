<?php
declare(strict_types=1);

/** تقسيم ملف SQL إلى عبارات منفصلة مع احترام النصوص والتعليقات (لا يدعم DELIMITER عمداً). */
function sql_split(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $q = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $i + 1 < $len ? $sql[$i + 1] : '';
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $q !== '`') {
                $buf .= $n;
                $i++;
            } elseif ($c === $q) {
                if ($n === $q) {
                    $buf .= $n;
                    $i++;
                } else {
                    $q = null;
                }
            }
            continue;
        }
        if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '/' && $n === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $buf .= $c;
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}
