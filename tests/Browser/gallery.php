<?php

declare(strict_types=1);

/*
 * Builds tests/Browser/screenshots/index.html from the manifest the suite writes.
 *
 * Every $this->shot($browser, 'caption') appends one JSON line; this turns those
 * lines into a browsable gallery grouped by scenario, in visit order. Run after
 * `composer test:browser`, or via `composer browser:gallery`.
 */

$base = __DIR__.'/screenshots';
$manifest = $base.'/manifest.jsonl';

if (! is_file($manifest)) {
    fwrite(STDERR, "No manifest at {$manifest} — run the browser suite first.\n");
    exit(1);
}

$steps = [];
foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true);

    if (is_array($row) && is_file($base.'/'.$row['file'])) {
        // Keyed by file: a re-run overwrites the image, so keep only the newest
        // manifest line for it instead of showing the same step twice.
        $steps[$row['file']] = $row;
    }
}

$scenarios = [];
foreach ($steps as $step) {
    $scenarios[$step['scenario']][$step['test']][] = $step;
}

ksort($scenarios);

$e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$title = static fn (string $v): string => ucfirst(str_replace(['_', '-'], ' ', preg_replace('/^test_/', '', $v)));

$total = count($steps);
$generated = date('Y-m-d H:i');

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Billing — browser suite gallery</title>
<style>
  :root { color-scheme: light dark; --bg:#fafafa; --fg:#18181b; --muted:#71717a; --card:#fff; --line:#e4e4e7; --accent:#0d9488; }
  @media (prefers-color-scheme: dark) { :root { --bg:#09090b; --fg:#fafafa; --muted:#a1a1aa; --card:#18181b; --line:#27272a; --accent:#2dd4bf; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.5 ui-sans-serif,system-ui,sans-serif; }
  header { padding:2.5rem 1.5rem 1.5rem; max-width:1400px; margin:0 auto; }
  h1 { margin:0 0 .35rem; font-size:1.6rem; letter-spacing:-.01em; }
  header p { margin:0; color:var(--muted); font-size:.9rem; }
  main { max-width:1400px; margin:0 auto; padding:0 1.5rem 4rem; }
  section { margin-top:2.5rem; }
  h2 { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:var(--accent); margin:0 0 .75rem; }
  h3 { font-size:1rem; font-weight:600; margin:1.75rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
  .row { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1rem; }
  figure { margin:0; background:var(--card); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  figure a { display:block; }
  img { display:block; width:100%; height:auto; background:#000; }
  figcaption { padding:.6rem .75rem; font-size:.8rem; color:var(--muted); display:flex; gap:.5rem; align-items:baseline; }
  .n { flex:none; width:1.4rem; height:1.4rem; border-radius:999px; background:var(--accent); color:var(--bg); font-size:.7rem; font-weight:700; display:grid; place-items:center; }
  nav { max-width:1400px; margin:0 auto; padding:0 1.5rem; display:flex; flex-wrap:wrap; gap:.4rem; }
  nav a { font-size:.8rem; color:var(--muted); text-decoration:none; border:1px solid var(--line); border-radius:999px; padding:.25rem .7rem; }
  nav a:hover { color:var(--fg); border-color:var(--accent); }
</style>
</head>
<body>
<header>
  <h1>Billing — browser suite gallery</h1>
  <p>{$total} steps from the Chrome end-to-end suite · generated {$generated}</p>
</header>
<nav>
HTML;

foreach (array_keys($scenarios) as $scenario) {
    $html .= sprintf('<a href="#%s">%s</a>', $e($scenario), $e($title($scenario)));
}

$html .= "</nav>\n<main>\n";

foreach ($scenarios as $scenario => $tests) {
    $html .= sprintf("<section id=\"%s\">\n  <h2>%s</h2>\n", $e($scenario), $e($title($scenario)));

    foreach ($tests as $test => $shots) {
        usort($shots, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        $html .= sprintf("  <h3>%s</h3>\n  <div class=\"row\">\n", $e($title($test)));

        foreach ($shots as $shot) {
            $html .= sprintf(
                "    <figure><a href=\"%s\" target=\"_blank\"><img src=\"%s\" alt=\"%s\" loading=\"lazy\"></a>"
                ."<figcaption><span class=\"n\">%d</span><span>%s</span></figcaption></figure>\n",
                $e($shot['file']), $e($shot['file']), $e($shot['label']), $shot['step'], $e($shot['label']),
            );
        }

        $html .= "  </div>\n";
    }

    $html .= "</section>\n";
}

$html .= "</main>\n</body>\n</html>\n";

file_put_contents($base.'/index.html', $html);

printf("Gallery written: %s (%d steps, %d scenarios)\n", $base.'/index.html', $total, count($scenarios));
