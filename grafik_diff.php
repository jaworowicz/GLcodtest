<?php
declare(strict_types=1);

// ─── ODS parser ──────────────────────────────────────────────────────────────

function parseODS(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie można otworzyć pliku ODS');
    }
    $xml = $zip->getFromName('content.xml');
    $zip->close();
    if (!$xml) throw new RuntimeException('Brak content.xml w pliku ODS');

    $NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    $NS_TEXT  = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    $dom = new SimpleXMLElement($xml);
    $dom->registerXPathNamespace('table', $NS_TABLE);

    $tables = $dom->xpath('//table:table');
    if (!$tables) throw new RuntimeException('Brak tabel w pliku ODS');

    // Prefer sheet named "Grafik", else first sheet
    $target = $tables[0];
    foreach ($tables as $t) {
        $a = $t->attributes($NS_TABLE);
        if ((string)($a['name'] ?? '') === 'Grafik') { $target = $t; break; }
    }

    $grid = [];
    foreach ($target->children($NS_TABLE) as $rowEl) {
        if ($rowEl->getName() !== 'table-row') continue;

        $ra = $rowEl->attributes($NS_TABLE);
        $rowRepeat = (int)($ra['number-rows-repeated'] ?? 1);
        // Cap: large repeat = trailing empty rows, keep max 2 copies
        $rowRepeat = ($rowRepeat > 3) ? 1 : $rowRepeat;

        $cells = [];
        foreach ($rowEl->children($NS_TABLE) as $cellEl) {
            $cn = $cellEl->getName();
            if ($cn !== 'table-cell' && $cn !== 'covered-table-cell') continue;

            $ca = $cellEl->attributes($NS_TABLE);
            $colRepeat = (int)($ca['number-columns-repeated'] ?? 1);
            // Cap: large repeat = trailing empty cells
            if ($colRepeat > 40) $colRepeat = 1;

            // Extract text value (handles direct text:p and nested text:span)
            $val = '';
            foreach ($cellEl->children($NS_TEXT) as $pEl) {
                if ($pEl->getName() !== 'p') continue;
                $val .= (string)$pEl;
                foreach ($pEl->children($NS_TEXT) as $spanEl) {
                    $val .= (string)$spanEl;
                }
            }
            $val = trim($val);

            for ($i = 0; $i < $colRepeat && count($cells) < 50; $i++) {
                $cells[] = $val;
            }
        }

        for ($r = 0; $r < $rowRepeat; $r++) {
            $grid[] = $cells;
            if (count($grid) > 600) break 2;
        }
    }

    return $grid;
}

// ─── HTML parser ─────────────────────────────────────────────────────────────

function parseHTML(string $path): array
{
    $html = file_get_contents($path);
    if ($html === false) throw new RuntimeException('Nie można odczytać pliku HTML');

    $doc = new DOMDocument('1.0', 'UTF-8');
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);

    // Find first table with border="1" or first table with <th>
    $target = null;
    foreach ($doc->getElementsByTagName('table') as $t) {
        if ($t->getAttribute('border') === '1') { $target = $t; break; }
    }
    if (!$target) {
        $tables = $doc->getElementsByTagName('table');
        if ($tables->length > 0) $target = $tables->item(0);
    }
    if (!$target) throw new RuntimeException('Brak tabeli w pliku HTML');

    $grid = [];
    foreach ($target->getElementsByTagName('tr') as $row) {
        $cells = [];
        foreach ($row->childNodes as $node) {
            if (!($node instanceof DOMElement)) continue;
            $tag = strtolower($node->nodeName);
            if ($tag !== 'td' && $tag !== 'th') continue;
            $cells[] = trim($node->textContent);
        }
        if ($cells) $grid[] = $cells;
    }

    return $grid;
}

// ─── Format detection ─────────────────────────────────────────────────────────

function detectFormat(string $tmpPath, string $origName): string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'ods') return 'ods';
    if (in_array($ext, ['xls', 'xlsx', 'html', 'htm'], true)) return 'html';
    // Fallback: check ZIP magic bytes (ODS is a ZIP archive)
    $f = fopen($tmpPath, 'rb');
    $magic = fread($f, 4);
    fclose($f);
    return ($magic === "PK\x03\x04") ? 'ods' : 'html';
}

// ─── Schedule extraction ──────────────────────────────────────────────────────

function normalizeDate(string $d): string
{
    // Unify separators: "01-07" → "01.07"
    return str_replace('-', '.', $d);
}

function extractSchedule(array $grid): array
{
    // ── Find header row ──────────────────────────────────────────────────────
    $headerIdx = -1;
    foreach ($grid as $rIdx => $row) {
        foreach ($row as $cell) {
            if (mb_stripos((string)$cell, 'Nazwisko') !== false
                || mb_stripos((string)$cell, 'NR SAP') !== false) {
                $headerIdx = $rIdx;
                break 2;
            }
        }
    }
    if ($headerIdx === -1) throw new RuntimeException('Nie znaleziono nagłówka tabeli (brak kolumny "Nazwisko" lub "NR SAP")');

    // ── Determine column indices from header row ──────────────────────────────
    $header     = $grid[$headerIdx];
    $idxSap     = -1;
    $idxName    = -1;
    $dayColumns = []; // colIndex → 'DD.MM'

    foreach ($header as $cIdx => $cell) {
        $cell = trim((string)$cell);
        if (mb_stripos($cell, 'NR SAP') !== false || mb_stripos($cell, 'numer osobowy') !== false) {
            $idxSap = $cIdx;
        } elseif (mb_stripos($cell, 'Nazwisko') !== false || mb_stripos($cell, 'imię i nazwisko') !== false) {
            $idxName = $cIdx;
        } elseif (preg_match('/^\d{2}[.\-]\d{2}$/', $cell)) {
            $dayColumns[$cIdx] = normalizeDate($cell);
        }
    }

    // ODS2-style: dates are in the row above the header (e.g. row 0 has "01-07", row 1 has "L.P.")
    if (empty($dayColumns) && $headerIdx > 0) {
        $prevRow = $grid[$headerIdx - 1];
        foreach ($prevRow as $cIdx => $cell) {
            $cell = trim((string)$cell);
            if (preg_match('/^\d{2}[.\-]\d{2}$/', $cell)) {
                $dayColumns[$cIdx] = normalizeDate($cell);
            }
        }
    }

    if (empty($dayColumns)) throw new RuntimeException('Nie znaleziono kolumn z datami (format DD.MM lub DD-MM)');

    // Defaults if columns not explicitly found
    if ($idxSap  === -1) $idxSap  = 1;
    if ($idxName === -1) $idxName = ($idxSap !== -1) ? $idxSap + 1 : 2;

    // ── Build schedule ────────────────────────────────────────────────────────
    $schedule = [];
    for ($rIdx = $headerIdx + 1; $rIdx < count($grid); $rIdx++) {
        $row   = $grid[$rIdx];
        $cell0 = trim((string)($row[0] ?? ''));

        // Employee row: first cell is a positive integer
        if (!ctype_digit($cell0) || $cell0 === '0') continue;

        $cellSap  = trim((string)($row[$idxSap]  ?? ''));
        $cellName = trim((string)($row[$idxName] ?? ''));

        // Name must contain at least one letter; skip placeholder rows (SAP = "!")
        if (!preg_match('/\p{L}/u', $cellName)) continue;
        if ($cellSap === '!' || $cellSap === '') continue;

        $days = [];
        foreach ($dayColumns as $cIdx => $dayLabel) {
            $shift = trim((string)($row[$cIdx] ?? ''));
            if ($shift !== '') $days[$dayLabel] = $shift;
        }

        $schedule[$cellSap] = [
            'name' => $cellName,
            'sap'  => $cellSap,
            'days' => $days,
        ];
    }

    return $schedule;
}

// ─── Diff computation ─────────────────────────────────────────────────────────

function computeDiff(array $oldSched, array $newSched): array
{
    $allSaps = array_unique(array_merge(array_keys($oldSched), array_keys($newSched)));
    $diffs   = [];

    foreach ($allSaps as $sap) {
        $oldEntry = $oldSched[$sap] ?? null;
        $newEntry = $newSched[$sap] ?? null;
        $name     = $newEntry['name'] ?? $oldEntry['name'] ?? $sap;
        $oldDays  = $oldEntry['days'] ?? [];
        $newDays  = $newEntry['days'] ?? [];

        $allDays = array_unique(array_merge(array_keys($oldDays), array_keys($newDays)));
        sort($allDays);

        $changes = [];
        foreach ($allDays as $day) {
            $from = $oldDays[$day] ?? '';
            $to   = $newDays[$day] ?? '';
            if ($from === $to) continue;
            $changes[] = ['day' => $day, 'from' => $from, 'to' => $to];
        }

        if ($changes) {
            $diffs[$sap] = ['name' => $name, 'sap' => $sap, 'changes' => $changes];
        }
    }

    uasort($diffs, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $diffs;
}

// ─── Entry point ──────────────────────────────────────────────────────────────

function parseFile(string $tmpPath, string $origName): array
{
    $fmt  = detectFormat($tmpPath, $origName);
    $grid = ($fmt === 'ods') ? parseODS($tmpPath) : parseHTML($tmpPath);
    return extractSchedule($grid);
}

$diffs   = [];
$error   = '';
$success = false;
$oldName = '';
$newName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $oldFile = $_FILES['old_file'] ?? null;
        $newFile = $_FILES['new_file'] ?? null;

        if (!$oldFile || !$newFile || empty($oldFile['tmp_name']) || empty($newFile['tmp_name'])) {
            throw new RuntimeException('Proszę wgrać oba pliki grafiku');
        }
        if ($oldFile['error'] !== UPLOAD_ERR_OK || $newFile['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Błąd przesyłania pliku — sprawdź rozmiar i format');
        }

        $oldName  = $oldFile['name'];
        $newName  = $newFile['name'];
        $oldSched = parseFile($oldFile['tmp_name'], $oldName);
        $newSched = parseFile($newFile['tmp_name'], $newName);
        $diffs    = computeDiff($oldSched, $newSched);
        $success  = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Porównywarka Grafików</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    [x-cloak] { display: none !important; }
  </style>
</head>
<body class="min-h-screen bg-gray-100 font-sans">

<div class="max-w-3xl mx-auto px-4 py-10">

  <!-- Header -->
  <div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900 tracking-tight">Porównywarka Grafików</h1>
    <p class="text-gray-500 mt-1 text-sm">Wgraj stary i nowy grafik (ODS lub HTML/XLS) aby zobaczyć różnice.</p>
  </div>

  <!-- Upload form -->
  <form method="POST" enctype="multipart/form-data"
        class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-8">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-semibold text-gray-700">Stary grafik
          <span class="font-normal text-gray-400 ml-1">(przed zmianami)</span>
        </label>
        <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-300
                      hover:border-blue-400 rounded-xl p-5 cursor-pointer transition-colors group bg-gray-50">
          <svg class="w-8 h-8 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          <span class="text-sm text-gray-500 group-hover:text-blue-600 transition-colors" id="old-label">Kliknij lub przeciągnij plik</span>
          <span class="text-xs text-gray-400">.ods / .xls / .html</span>
          <input type="file" name="old_file" accept=".ods,.xls,.html,.htm" class="hidden"
                 onchange="document.getElementById('old-label').textContent = this.files[0]?.name ?? 'Kliknij lub przeciągnij plik'">
        </label>
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-semibold text-gray-700">Nowy grafik
          <span class="font-normal text-gray-400 ml-1">(po zmianach)</span>
        </label>
        <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-300
                      hover:border-blue-400 rounded-xl p-5 cursor-pointer transition-colors group bg-gray-50">
          <svg class="w-8 h-8 text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          <span class="text-sm text-gray-500 group-hover:text-blue-600 transition-colors" id="new-label">Kliknij lub przeciągnij plik</span>
          <span class="text-xs text-gray-400">.ods / .xls / .html</span>
          <input type="file" name="new_file" accept=".ods,.xls,.html,.htm" class="hidden"
                 onchange="document.getElementById('new-label').textContent = this.files[0]?.name ?? 'Kliknij lub przeciągnij plik'">
        </label>
      </div>

    </div>

    <div class="mt-5 flex items-center gap-3">
      <button type="submit"
              class="bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-semibold
                     py-2.5 px-7 rounded-xl transition-colors shadow-sm">
        Porównaj grafiki
      </button>
      <?php if ($success): ?>
      <span class="text-sm text-gray-400">
        <?= h($oldName) ?> vs <?= h($newName) ?>
      </span>
      <?php endif; ?>
    </div>
  </form>

  <!-- Error -->
  <?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-5 py-4 rounded-xl mb-6 flex gap-3 items-start">
    <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
      <p class="font-semibold">Błąd parsowania</p>
      <p class="text-sm mt-0.5"><?= h($error) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Results -->
  <?php if ($success): ?>

    <?php if (empty($diffs)): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-5 py-4 rounded-xl flex gap-3 items-center">
      <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
      </svg>
      <span class="font-medium">Brak różnic — grafiki są identyczne.</span>
    </div>

    <?php else: ?>

    <!-- Controls bar -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
      <p class="text-sm text-gray-600">
        Zmiany u <span class="font-bold text-gray-900"><?= count($diffs) ?></span>
        <?= count($diffs) === 1 ? 'pracownika' : 'pracowników' ?>,
        łącznie <span class="font-bold text-gray-900" id="totalChanges">
          <?= array_sum(array_map(fn($d) => count($d['changes']), $diffs)) ?>
        </span> wpisów.
      </p>
      <div class="flex gap-2 flex-wrap">
        <button onclick="checkAll(true)"
                class="text-xs bg-white border border-gray-300 hover:bg-gray-50 text-gray-700
                       px-3 py-1.5 rounded-lg transition-colors font-medium shadow-sm">
          ✓ Zaznacz wszystko
        </button>
        <button onclick="checkAll(false)"
                class="text-xs bg-white border border-gray-300 hover:bg-gray-50 text-gray-700
                       px-3 py-1.5 rounded-lg transition-colors font-medium shadow-sm">
          ○ Odznacz wszystko
        </button>
        <button onclick="toggleHideDone()" id="hideBtn"
                class="text-xs bg-white border border-gray-300 hover:bg-gray-50 text-gray-700
                       px-3 py-1.5 rounded-lg transition-colors font-medium shadow-sm">
          Ukryj zaznaczone
        </button>
      </div>
    </div>

    <!-- Employee diff cards -->
    <?php foreach ($diffs as $sap => $diff): ?>
    <?php $totalChanges = count($diff['changes']); ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 mb-4 overflow-hidden employee-card"
         data-sap="<?= h((string)$sap) ?>">

      <!-- Card header -->
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div>
          <h2 class="font-semibold text-gray-900 text-base"><?= h($diff['name']) ?></h2>
          <span class="text-xs text-gray-400">SAP: <?= h($diff['sap']) ?></span>
        </div>
        <div class="flex items-center gap-2">
          <span class="done-count text-xs text-gray-400 font-medium">0/<?= $totalChanges ?></span>
          <span class="text-xs bg-blue-50 text-blue-700 border border-blue-100 px-2.5 py-1 rounded-full font-semibold">
            <?= $totalChanges ?> <?= $totalChanges === 1 ? 'zmiana' : 'zmian' ?>
          </span>
        </div>
      </div>

      <!-- Changes list -->
      <ul class="divide-y divide-gray-50 px-2 py-2">
        <?php foreach ($diff['changes'] as $idx => $change): ?>
        <?php
          $from  = $change['from'];
          $to    = $change['to'];
          $day   = $change['day'];
          $cbId  = 'cb_' . preg_replace('/\W/', '_', (string)$sap) . '_' . $idx;
        ?>
        <li class="change-item flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
          <input type="checkbox" id="<?= $cbId ?>" class="change-cb w-4 h-4 rounded border-gray-300 cursor-pointer
                 accent-blue-600 flex-shrink-0" onchange="onCheck(this)">
          <label for="<?= $cbId ?>" class="flex flex-wrap items-center gap-2 text-sm cursor-pointer select-none w-full">
            <span class="font-semibold text-gray-700 w-12 tabular-nums"><?= h($day) ?></span>

            <?php if ($from !== '' && $to !== ''): ?>
              <!-- Changed shift -->
              <span class="inline-flex items-center gap-1 bg-red-50 text-red-700 border border-red-200
                           px-2 py-0.5 rounded-full text-xs font-medium">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                </svg>
                <?= h($from) ?>
              </span>
              <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
              </svg>
              <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-200
                           px-2 py-0.5 rounded-full text-xs font-medium">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                <?= h($to) ?>
              </span>

            <?php elseif ($from !== ''): ?>
              <!-- Removed shift -->
              <span class="inline-flex items-center gap-1 bg-red-50 text-red-700 border border-red-200
                           px-2 py-0.5 rounded-full text-xs font-medium">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                </svg>
                usuń <?= h($from) ?>
              </span>

            <?php else: ?>
              <!-- Added shift -->
              <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-200
                           px-2 py-0.5 rounded-full text-xs font-medium">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                dodaj <?= h($to) ?>
              </span>

            <?php endif; ?>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
  <?php endif; ?>

</div><!-- /container -->

<script>
(function () {
  let hideDone = false;

  function updateItem(cb) {
    const item = cb.closest('.change-item');
    const label = item.querySelector('label');
    if (cb.checked) {
      label.classList.add('line-through', 'opacity-40');
      if (hideDone) item.classList.add('hidden');
    } else {
      label.classList.remove('line-through', 'opacity-40');
      item.classList.remove('hidden');
    }
    updateCardCounter(cb.closest('.employee-card'));
  }

  function updateCardCounter(card) {
    if (!card) return;
    const cbs    = card.querySelectorAll('.change-cb');
    const done   = [...cbs].filter(c => c.checked).length;
    const total  = cbs.length;
    const counter = card.querySelector('.done-count');
    if (counter) counter.textContent = done + '/' + total;

    const allDone = done === total;
    card.classList.toggle('opacity-50', allDone);
  }

  window.onCheck = function (cb) { updateItem(cb); };

  window.checkAll = function (state) {
    document.querySelectorAll('.change-cb').forEach(cb => {
      cb.checked = state;
      updateItem(cb);
    });
  };

  window.toggleHideDone = function () {
    hideDone = !hideDone;
    const btn = document.getElementById('hideBtn');
    btn.textContent = hideDone ? 'Pokaż zaznaczone' : 'Ukryj zaznaczone';
    document.querySelectorAll('.change-item').forEach(item => {
      const cb = item.querySelector('.change-cb');
      if (hideDone && cb.checked) item.classList.add('hidden');
      else item.classList.remove('hidden');
    });
  };
})();
</script>

</body>
</html>
