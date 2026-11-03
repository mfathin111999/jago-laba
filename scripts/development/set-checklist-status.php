<?php
// Usage: php set-checklist-status.php <status> <ID> [<ID>...]  (updates .md, .csv, .json checklist 20261101)
$status = $argv[1] ?? null; $ids = array_slice($argv, 2);
if (!$status || !$ids) { fwrite(STDERR, "usage: set-checklist-status.php <status> <ID>...\n"); exit(1); }
$dir = dirname(__DIR__, 2).'/docs/architecture/'; $base = $dir.'laravel-feature-checklist-20261101';
$md = file($base.'.md'); $n = 0;
foreach ($md as &$l) foreach ($ids as $id) if (str_starts_with($l, "| $id |")) { $c = explode('|', $l); $c[3] = " $status "; $l = implode('|', $c); $n++; }
file_put_contents($base.'.md', implode('', $md));
$rows = array_map('str_getcsv', file($base.'.csv', FILE_IGNORE_NEW_LINES)); $h = array_shift($rows); $si = array_search('status', $h);
foreach ($rows as &$r) if (in_array($r[0], $ids, true)) $r[$si] = $status;
$f = fopen($base.'.csv', 'w'); fputcsv($f, $h); foreach ($rows as $r) fputcsv($f, $r); fclose($f);
$j = json_decode(file_get_contents($base.'.json'), true);
foreach ($j['items'] as &$it) if (in_array($it['id'], $ids, true)) $it['status'] = $status;
file_put_contents($base.'.json', json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
echo "md rows updated: $n of ".count($ids)."\n";
