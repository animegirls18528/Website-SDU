<?php
/**
 * Server-side document filtering shared by api/search.php and api/export.php.
 * Every query is limited to the folders the current user may access.
 */
require_once __DIR__ . '/helpers.php';

const DOC_STATUS_FILTERS = ['', 'active', 'expired', 'expiring', 'draft', 'pending', 'destruction', 'trash', 'nofile'];

/** Returns [whereSql, params] for documents aliased `d`. */
function buildDocumentFilter($pdo, array $f) {
    $where = [];
    $params = [];

    $status = in_array($f['status'] ?? '', DOC_STATUS_FILTERS, true) ? ($f['status'] ?? '') : '';
    if ($status === 'trash') {
        if (!isAdmin()) $where[] = '1=0';
        $where[] = 'd.DeletedAt IS NOT NULL';
    } else {
        $where[] = 'd.DeletedAt IS NULL';
    }
    switch ($status) {
        case 'active':   $where[] = "(d.ExpiryDate IS NULL OR d.ExpiryDate >= CURDATE())"; break;
        case 'expired':  $where[] = "d.ExpiryDate < CURDATE()"; break;
        case 'expiring':
            $where[] = "d.ExpiryDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $params[] = max(1, (int)getSetting($pdo, 'expiry_warning_days', '90'));
            break;
        case 'draft':       $where[] = "d.WorkflowStatus = 'Draft'"; break;
        case 'pending':     $where[] = "d.WorkflowStatus = 'PendingReview'"; break;
        case 'destruction': $where[] = "d.DestructionRequestedAt IS NOT NULL"; break;
        case 'nofile':      $where[] = "(d.FileId IS NULL OR d.FileId = '')"; break;
    }

    if (!empty($f['folderId'])) {
        if ($f['folderId'] === 'none') {
            $where[] = 'd.FolderId IS NULL';
        } else {
            $where[] = 'd.FolderId = ?';
            $params[] = (int)$f['folderId'];
        }
    }

    $dateField = ($f['dateField'] ?? '') === 'created' ? 'DATE(d.CreatedAt)' : 'd.PaymentDate';
    if (!empty($f['dateFrom']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['dateFrom'])) {
        $where[] = "$dateField >= ?";
        $params[] = $f['dateFrom'];
    }
    if (!empty($f['dateTo']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['dateTo'])) {
        $where[] = "$dateField <= ?";
        $params[] = $f['dateTo'];
    }

    // ---- advanced filters (index fields live in the CustomData JSON) ----
    $jsonField = fn($name) => ["JSON_UNQUOTE(JSON_EXTRACT(d.CustomData, ?))", '$."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"'];

    // Thai fiscal year (Oct–Sep, Buddhist era): from the payment date, or the imported "ปีงบประมาณ" field
    if (!empty($f['fiscalYear']) && ctype_digit((string)$f['fiscalYear'])) {
        [$expr, $path] = $jsonField('ปีงบประมาณ');
        $where[] = "((YEAR(d.PaymentDate) + 543 + (MONTH(d.PaymentDate) >= 10)) = ? OR $expr = ?)";
        array_push($params, (int)$f['fiscalYear'], $path, (string)$f['fiscalYear']);
    }

    $amountName = getSetting($pdo, 'amount_field', 'จำนวนเงิน');
    foreach (['amountMin' => '>=', 'amountMax' => '<='] as $key => $op) {
        if (isset($f[$key]) && $f[$key] !== '' && is_numeric(str_replace(',', '', $f[$key]))) {
            [$expr, $path] = $jsonField($amountName);
            $where[] = "CAST(REPLACE($expr, ',', '') AS DECIMAL(15,2)) $op ?";
            array_push($params, $path, (float)str_replace(',', '', $f[$key]));
        }
    }

    if (!empty($f['department'])) {
        [$expr, $path] = $jsonField(getSetting($pdo, 'department_field', 'หน่วยงาน'));
        $where[] = "$expr LIKE ?";
        array_push($params, $path, '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['department'])) . '%');
    }

    if (!empty($f['fieldName']) && isset($f['fieldValue']) && trim($f['fieldValue']) !== '') {
        [$expr, $path] = $jsonField(mb_substr($f['fieldName'], 0, 100));
        $where[] = "$expr LIKE ?";
        array_push($params, $path, '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['fieldValue'])) . '%');
    }

    if (!empty($f['locationId'])) {
        if ($f['locationId'] === 'none') {
            $where[] = 'd.LocationId IS NULL';
        } else {
            $where[] = 'd.LocationId = ?';
            $params[] = (int)$f['locationId'];
        }
    }

    if (($f['loan'] ?? '') === 'onloan') $where[] = 'EXISTS (SELECT 1 FROM loans lo WHERE lo.DocumentID = d.ID AND lo.ReturnedAt IS NULL)';
    if (($f['loan'] ?? '') === 'overdue') $where[] = 'EXISTS (SELECT 1 FROM loans lo WHERE lo.DocumentID = d.ID AND lo.ReturnedAt IS NULL AND lo.DueDate < CURDATE())';
    if (($f['pii'] ?? '') === '1') $where[] = 'd.PiiFlag > 0';

    // Every word must appear in the ID, title, tags, index fields or the document's text (see document_search)
    $q = normalizeSearchText(mb_substr((string)($f['q'] ?? ''), 0, 200));
    if ($q !== '') {
        $conds = [];
        foreach (array_slice(explode(' ', $q), 0, 8) as $term) {
            $conds[] = 's.SearchText LIKE ?';
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
        }
        // Scan the narrow search table once, then fetch matching documents by primary key
        $from = 'FROM (SELECT s.DocumentID FROM document_search s WHERE ' . implode(' AND ', $conds) . ') m
                 STRAIGHT_JOIN documents d ON d.ID = m.DocumentID';
        $searchParams = array_splice($params, count($params) - count($conds));
        $params = array_merge($searchParams, $params);
    } else {
        $from = 'FROM documents d';
    }

    $where[] = folderScopeSql($pdo, $params);
    return [$from, implode(' AND ', $where), $params, $q !== ''];
}

function documentOrderSql($sort) {
    switch ($sort) {
        case 'id_asc':       return 'd.ID ASC';
        case 'id_desc':      return 'd.ID DESC';
        case 'payment_desc': return 'd.PaymentDate DESC, d.ID DESC';
        case 'payment_asc':  return 'd.PaymentDate ASC, d.ID ASC';
        case 'expiry_asc':   return 'd.ExpiryDate IS NULL, d.ExpiryDate ASC';
        default:             return 'd.CreatedAt DESC, d.ID DESC';
    }
}

// ---------- search index ----------

/** Lowercase, collapse whitespace and unify Thai SARA AM typed as NIKHAHIT + SARA AA. */
function normalizeSearchText($s) {
    $s = str_replace("\u{0E4D}\u{0E32}", "\u{0E33}", (string)$s);
    $s = mb_strtolower($s, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function searchTextForRow(array $d) {
    $parts = [$d['ID'], $d['Filename'], $d['Tags'] ?? ''];
    $custom = json_decode((string)($d['CustomData'] ?? ''), true);
    if (is_array($custom)) {
        array_walk_recursive($custom, function ($v, $k) use (&$parts) {
            if ($k !== 'LegacyID' && $v !== null && $v !== '') $parts[] = (string)$v;
        });
    }
    if (!empty($d['OcrText'])) $parts[] = mb_substr($d['OcrText'], 0, 300000);
    return normalizeSearchText(implode(' | ', $parts));
}

function updateSearchIndex($pdo, $docId) {
    $stmt = $pdo->prepare("SELECT ID, Filename, Tags, CustomData, OcrText FROM documents WHERE ID = ?");
    $stmt->execute([$docId]);
    $d = $stmt->fetch();
    if (!$d) return;
    $pdo->prepare("INSERT INTO document_search (DocumentID, SearchText) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE SearchText = VALUES(SearchText)")->execute([$docId, searchTextForRow($d)]);
}

/** Index documents that have no search row yet (e.g. rows added by import tools). Returns the count. */
function rebuildMissingSearchRows($pdo, $limit = 0) {
    $sql = "SELECT d.ID, d.Filename, d.Tags, d.CustomData, d.OcrText FROM documents d
            LEFT JOIN document_search s ON s.DocumentID = d.ID WHERE s.DocumentID IS NULL" . ($limit ? " LIMIT " . (int)$limit : "");
    $rows = $pdo->query($sql)->fetchAll();
    $ins = $pdo->prepare("INSERT IGNORE INTO document_search (DocumentID, SearchText) VALUES (?, ?)");
    $pdo->beginTransaction();
    foreach ($rows as $i => $d) {
        $ins->execute([$d['ID'], searchTextForRow($d)]);
        if ($i % 2000 === 1999) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit();
    return count($rows);
}
