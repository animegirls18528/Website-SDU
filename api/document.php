<?php
require_once '../config/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once '../config/storage.php';
require_once '../config/search.php';
require_once '../config/pii.php';
require_once '../config/access.php';
require_once '../vendor/autoload.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && isset($_POST['action'])) {
    $input = $_POST;
}
$input = $input ?: [];
$action = $input['action'] ?? '';
$me = (int)$_SESSION['user_id'];

const WORKFLOW_LABELS = ['Draft' => 'ฉบับร่าง', 'PendingReview' => 'รอตรวจสอบ', 'Approved' => 'อนุมัติแล้ว'];

/** Load a document the current user may access, or fail. */
function loadDoc($pdo, $id, $allowDeleted = false) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE ID = ?");
    $stmt->execute([(string)$id]);
    $doc = $stmt->fetch();
    if (!$doc || !canAccessFolder($pdo, $doc['FolderId'])) jsonFail(404, 'ไม่พบเอกสาร หรือไม่มีสิทธิ์เข้าถึงแฟ้มนี้');
    if ($doc['DeletedAt'] && !$allowDeleted) jsonFail(400, 'เอกสารนี้อยู่ในถังขยะ');
    return $doc;
}

function publicDoc($pdo, $id) {
    $stmt = $pdo->prepare("SELECT d.ID, d.Filename, d.FolderId, COALESCE(f.FolderName, '') AS FolderName, d.PaymentDate, d.ExpiryDate,
                                  CASE WHEN d.ExpiryDate < CURDATE() THEN 'Expired' ELSE 'Active' END AS Status,
                                  d.WorkflowStatus, d.Tags, d.CustomData, d.CreatedAt, d.UpdatedAt, d.DeletedAt, d.DeleteReason,
                                  d.DestructionRequestedAt, d.DestructionReason, d.VerifyToken, d.RowVersion,
                                  (d.OcrText IS NOT NULL) AS HasText, CHAR_LENGTH(d.OcrText) AS TextLength,
                                  cu.Username AS CreatedByName, du.Username AS DestructionRequestedByName,
                                  d.PiiFlag, d.PiiSummary, d.LocationId, d.LocationNote, d.CoverPrintedAt, d.ExpectedPages,
                                  lc.Room, lc.Cabinet, lc.Shelf, lc.Box
                           FROM documents d
                           LEFT JOIN folders f ON f.ID = d.FolderId
                           LEFT JOIN locations lc ON lc.ID = d.LocationId
                           LEFT JOIN users cu ON cu.ID = d.CreatedBy
                           LEFT JOIN users du ON du.ID = d.DestructionRequestedBy
                           WHERE d.ID = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    $doc['CustomData'] = json_decode($doc['CustomData'] ?: '{}', true) ?: new stdClass();
    $doc['HasText'] = (bool)$doc['HasText'];
    $doc['Files'] = documentFiles($pdo, $id);
    $doc['HasFiles'] = count($doc['Files']) > 0;
    $doc['PiiFlag'] = (int)$doc['PiiFlag'];
    $doc['LocationLabel'] = implode(' › ', array_filter([$doc['Room'], $doc['Cabinet'] ? 'ตู้ ' . $doc['Cabinet'] : '',
        $doc['Shelf'] ? 'ชั้น ' . $doc['Shelf'] : '', $doc['Box'] ? 'กล่อง ' . $doc['Box'] : '']));
    unset($doc['Room'], $doc['Cabinet'], $doc['Shelf'], $doc['Box']);
    $stmt = $pdo->prepare("SELECT ID, BorrowerName, Purpose, LoanedAt, DueDate FROM loans WHERE DocumentID = ? AND ReturnedAt IS NULL");
    $stmt->execute([$id]);
    $doc['Loan'] = $stmt->fetch() ?: null;
    // What this viewer may do with the files
    $doc['Access'] = [
        'seePii' => canSeePii($pdo),
        'openFiles' => $doc['PiiFlag'] === 0 || canSeePii($pdo),
        'download' => canDownloadFiles($pdo),
    ];
    if (!$doc['Access']['seePii']) $doc['CustomData'] = (object)maskPiiDeep((array)$doc['CustomData']);
    return $doc;
}

/** Validated list of staged files from a comma-separated or array value. */
function stagedFilesFrom($value) {
    $refs = is_array($value) ? $value : explode(',', (string)$value);
    $rels = [];
    foreach ($refs as $ref) {
        if (trim($ref) === '') continue;
        $rel = normalizeFileRef($ref);
        if (!$rel || !isStaged($rel)) jsonFail(400, 'ไฟล์แนบไม่ถูกต้องหรือหมดอายุ กรุณาอัปโหลดใหม่');
        $rels[] = $rel;
    }
    return $rels;
}

function cleanTags($tags) {
    $list = is_array($tags) ? $tags : preg_split('/[,\n]+/u', (string)$tags);
    $list = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 40), $list), 'strlen')));
    return $list ? mb_substr(implode(', ', $list), 0, 500) : null;
}

/** Payment date + retention years. 29 Feb lands on 28 Feb in non-leap years instead of rolling into March. */
function expiryFor($pdo, $docId, $paymentDate) {
    if (!$paymentDate) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $paymentDate));
    $y += retentionYearsFor($pdo, $docId);
    $d = min($d, (int)date('t', mktime(0, 0, 0, $m, 1, $y)));
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** null for empty input, 'YYYY-MM-DD' for a real calendar date, otherwise fail with 400. */
function validDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        || (int)$m[1] < 1900 || (int)$m[1] > 2200) {
        jsonFail(400, 'วันที่จ่ายเงินไม่ถูกต้อง');
    }
    return $value;
}

function requireFolderExists($pdo, $folderId) {
    if ($folderId === null) return;
    $stmt = $pdo->prepare("SELECT 1 FROM folders WHERE ID = ?");
    $stmt->execute([$folderId]);
    if (!$stmt->fetchColumn()) jsonFail(400, 'ไม่พบแฟ้มจัดเก็บที่เลือก กรุณาโหลดหน้าใหม่');
}

// IDs go into CODE128 barcodes and the label under the QR code, so only ASCII is allowed
const DOC_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/';
const DOC_ID_MESSAGE = 'รหัสเอกสารใช้ได้เฉพาะ A-Z, 0-9 และ . _ / - (ไม่เกิน 100 ตัว) เพื่อให้พิมพ์บาร์โค้ดได้';

try {
    switch ($action) {
        case 'get':
            $doc = loadDoc($pdo, $input['ID'] ?? '', isAdmin());
            $out = publicDoc($pdo, $doc['ID']);
            $out['Versions'] = array_values(array_filter(documentFiles($pdo, $doc['ID'], false), fn($f) => !$f['IsCurrent']));
            $stmt = $pdo->prepare("SELECT a.Action, a.Details, a.CreatedAt, a.IPAddress, COALESCE(u.Username, 'System') AS Username
                                   FROM audit_logs a LEFT JOIN users u ON u.ID = a.UserID
                                   WHERE a.DocumentID = ? ORDER BY a.ID DESC LIMIT 100");
            $stmt->execute([$doc['ID']]);
            $out['History'] = $stmt->fetchAll();
            // PDPA: record who saw unmasked personal data (once per user and document every 30 minutes)
            if (!empty($out['PiiFlag']) && !empty($out['Access']['seePii'])) {
                $seen = $_SESSION['pii_seen'][$doc['ID']] ?? 0;
                if (time() - $seen > 1800) {
                    $_SESSION['pii_seen'][$doc['ID']] = time();
                    addAuditLog($pdo, $me, 'View PII', 'เปิดดูรายละเอียดที่มีข้อมูลส่วนบุคคล: ' . ($out['PiiSummary'] ?? ''), $doc['ID']);
                }
            }
            echo json_encode(['success' => true, 'document' => $out]);
            break;

        case 'createDocument':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $filename = trim((string)($input['Filename'] ?? ''));
            $folderId = ($input['FolderId'] ?? '') !== '' ? (int)$input['FolderId'] : null;
            $paymentDate = validDate($input['PaymentDate'] ?? '');
            if ($filename === '') jsonFail(400, 'กรุณาระบุชื่อเอกสาร');
            requireFolderExists($pdo, $folderId);
            if (!canAccessFolder($pdo, $folderId)) jsonFail(403, 'ไม่มีสิทธิ์จัดเก็บในแฟ้มนี้');
            $rels = stagedFilesFrom($input['newFileId'] ?? []);

            $workflow = $input['WorkflowStatus'] ?? 'PendingReview';
            if (!isset(WORKFLOW_LABELS[$workflow]) || ($workflow === 'Approved' && !isAdmin())) $workflow = 'PendingReview';

            $pdo->beginTransaction();
            $prefix = (string)($input['Prefix'] ?? '');
            $requestedId = trim((string)($input['ID'] ?? ''));
            if (!empty($input['autoId']) && $prefix !== '') {
                // The suggested number may have been taken meanwhile; reserve one atomically
                $docId = reserveNextId($pdo, $prefix);
            } else {
                $docId = $requestedId;
                if (!preg_match(DOC_ID_PATTERN, $docId)) {
                    $pdo->rollBack();
                    jsonFail(400, DOC_ID_MESSAGE);
                }
                $stmt = $pdo->prepare("SELECT 1 FROM documents WHERE ID = ?");
                $stmt->execute([$docId]);
                if ($stmt->fetchColumn()) { $pdo->rollBack(); jsonFail(409, "รหัสเอกสาร $docId มีอยู่แล้ว"); }
            }

            $expiryDate = expiryFor($pdo, $docId, $paymentDate);
            $status = ($expiryDate && strtotime($expiryDate) < time()) ? 'Expired' : 'Active';
            $customData = json_encode((object)($input['CustomData'] ?? []), JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("INSERT INTO documents (ID, Filename, FolderId, PaymentDate, ExpiryDate, Status, FileId, CustomData,
                                                          Tags, WorkflowStatus, CreatedBy, UpdatedAt, VerifyToken)
                                   VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, NOW(), ?)");
            $stmt->execute([$docId, $filename, $folderId, $paymentDate, $expiryDate, $status, $customData,
                            cleanTags($input['Tags'] ?? ''), $workflow, $me, bin2hex(random_bytes(16))]);
            if (!empty($input['ExpectedPages'])) {
                $pdo->prepare("UPDATE documents SET ExpectedPages = ? WHERE ID = ?")->execute([max(1, (int)$input['ExpectedPages']), $docId]);
            }
            $attach = attachFiles($pdo, $docId, $rels, 'append', $me);
            updateSearchIndex($pdo, $docId);
            $pdo->commit();

            addAuditLog($pdo, $me, "Create Document", "Created document ID: $docId, Name: $filename ({$attach['attached']} file(s))", $docId);
            if ($attach['attached']) spawnBackground('extract_text.php', [$docId]);

            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $docId),
                              'idChanged' => $requestedId !== '' && $requestedId !== $docId, 'stampFailed' => $attach['stampFailed']]);
            break;

        case 'updateDocumentMetadata':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = loadDoc($pdo, $input['oldId'] ?? '');
            $oldId = $doc['ID'];
            $docId = trim((string)($input['newId'] ?? $oldId));
            if ($docId !== $oldId && !preg_match(DOC_ID_PATTERN, $docId)) jsonFail(400, DOC_ID_MESSAGE);
            // Someone else saved this document after it was opened: refuse rather than silently overwrite
            if (isset($input['RowVersion']) && (int)$input['RowVersion'] !== (int)$doc['RowVersion']) {
                jsonFail(409, 'มีผู้อื่นแก้ไขเอกสารนี้หลังจากที่คุณเปิด กรุณาปิดแล้วเปิดเอกสารใหม่เพื่อดูข้อมูลล่าสุด');
            }
            if ($docId !== $oldId) {
                $stmt = $pdo->prepare("SELECT 1 FROM documents WHERE ID = ?");
                $stmt->execute([$docId]);
                if ($stmt->fetchColumn()) jsonFail(409, "รหัสเอกสาร $docId มีอยู่แล้ว");
            }
            $filename = trim((string)($input['Filename'] ?? $doc['Filename']));
            if ($filename === '') jsonFail(400, 'กรุณาระบุชื่อเอกสาร');
            $folderId = array_key_exists('FolderId', $input) && $input['FolderId'] !== '' ? (int)$input['FolderId'] : $doc['FolderId'];
            if ((int)$folderId !== (int)$doc['FolderId']) requireFolderExists($pdo, $folderId);
            if (!canAccessFolder($pdo, $folderId)) jsonFail(403, 'ไม่มีสิทธิ์จัดเก็บในแฟ้มนี้');
            $paymentDate = array_key_exists('PaymentDate', $input) ? validDate($input['PaymentDate']) : $doc['PaymentDate'];
            $expiryDate = expiryFor($pdo, $docId, $paymentDate);
            $status = ($expiryDate && strtotime($expiryDate) < time()) ? 'Expired' : 'Active';
            $customData = array_key_exists('CustomData', $input)
                ? json_encode((object)$input['CustomData'], JSON_UNESCAPED_UNICODE) : $doc['CustomData'];
            $tags = array_key_exists('Tags', $input) ? cleanTags($input['Tags']) : $doc['Tags'];
            $rels = stagedFilesFrom($input['newFileId'] ?? []);
            $mode = ($input['appendFiles'] ?? true) === false ? 'replace' : 'append';

            // A staff change to an approved document goes back to review
            $changed = $docId !== $oldId || $filename !== $doc['Filename'] || (string)$folderId !== (string)$doc['FolderId']
                || (string)$paymentDate !== (string)$doc['PaymentDate'] || (string)$tags !== (string)$doc['Tags']
                || json_decode((string)$customData, true) != json_decode((string)$doc['CustomData'], true) || $rels;
            $workflow = $doc['WorkflowStatus'];
            $backToReview = $changed && !isAdmin() && $workflow === 'Approved';
            if ($backToReview) $workflow = 'PendingReview';

            $pdo->beginTransaction();
            // document_files follows the ID change through ON UPDATE CASCADE
            $stmt = $pdo->prepare("UPDATE documents SET ID=?, Filename=?, FolderId=?, PaymentDate=?, ExpiryDate=?, Status=?,
                                   CustomData=?, Tags=?, WorkflowStatus=?, UpdatedAt=NOW(), RowVersion=RowVersion+1 WHERE ID=?");
            $stmt->execute([$docId, $filename, $folderId, $paymentDate, $expiryDate, $status, $customData, $tags, $workflow, $oldId]);
            if ($docId !== $oldId) {
                $pdo->prepare("UPDATE audit_logs SET DocumentID = ? WHERE DocumentID = ?")->execute([$docId, $oldId]);
                // The ID printed under the QR code must follow the new ID
                restampCurrentFiles($pdo, $docId, $me);
            }
            $attach = attachFiles($pdo, $docId, $rels, $mode, $me);
            updateSearchIndex($pdo, $docId);
            $pdo->commit();
            if ($backToReview) {
                addAuditLog($pdo, $me, 'Workflow', 'อนุมัติแล้ว → รอตรวจสอบ (แก้ไขหลังอนุมัติ)', $docId);
            }

            $what = $docId !== $oldId ? "Updated document ID: $oldId to $docId" : "Updated document $docId";
            if ($attach['attached']) $what .= $mode === 'replace' ? " · replaced files (new version)" : " · added {$attach['attached']} file(s)";
            addAuditLog($pdo, $me, "Update Document", "$what, Name: $filename", $docId);
            if ($attach['attached']) spawnBackground('extract_text.php', [$docId]);

            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $docId), 'stampFailed' => $attach['stampFailed'],
                              'backToReview' => $backToReview]);
            break;

        case 'replaceFile':
            // One file rescanned or edited (pages inserted, replaced, removed): becomes a new version of that file
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            if ((int)($input['RowVersion'] ?? -1) !== (int)$doc['RowVersion']) jsonFail(409, 'มีผู้อื่นแก้ไขเอกสารนี้หลังจากที่คุณเปิด กรุณาเปิดเอกสารใหม่');
            $rels = stagedFilesFrom($input['newFileId'] ?? []);
            if (count($rels) !== 1) jsonFail(400, 'ต้องมีไฟล์ใหม่ 1 ไฟล์');
            $pdo->beginTransaction();
            $r = replaceSingleFile($pdo, $doc['ID'], (int)($input['FileRowId'] ?? 0), $rels[0], $me);
            if ($r === null) { $pdo->rollBack(); jsonFail(404, 'ไม่พบไฟล์ที่จะแทนที่'); }
            $review = !isAdmin() && $doc['WorkflowStatus'] === 'Approved';
            $pdo->prepare("UPDATE documents SET UpdatedAt = NOW(), RowVersion = RowVersion + 1" . ($review ? ", WorkflowStatus = 'PendingReview'" : '') . " WHERE ID = ?")
                ->execute([$doc['ID']]);
            $pdo->commit();
            addAuditLog($pdo, $me, 'Update Document', 'แก้ไขหน้าเอกสาร (สร้างเวอร์ชันใหม่ของไฟล์)' . ($review ? ' · ส่งกลับตรวจสอบ' : ''), $doc['ID']);
            spawnBackground('extract_text.php', [$doc['ID']]);
            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $doc['ID']), 'stampFailed' => $r['stampFailed']]);
            break;

        case 'setPii':
            // Mark/unmark personal data by hand (2 = marked by a person, 0 = cleared and re-checked automatically)
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            if (!empty($input['flag'])) {
                $pdo->prepare("UPDATE documents SET PiiFlag = 2, PiiSummary = ? WHERE ID = ?")
                    ->execute([mb_substr(trim((string)($input['Summary'] ?? '')), 0, 255) ?: 'ระบุโดยผู้ดูแลระบบ', $doc['ID']]);
            } else {
                $pdo->prepare("UPDATE documents SET PiiFlag = 0, PiiSummary = NULL WHERE ID = ?")->execute([$doc['ID']]);
                updatePiiFlag($pdo, $doc['ID']);
            }
            addAuditLog($pdo, $me, 'PDPA', !empty($input['flag']) ? 'ระบุว่ามีข้อมูลส่วนบุคคล' : 'ยกเลิกการระบุข้อมูลส่วนบุคคล', $doc['ID']);
            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $doc['ID'])]);
            break;

        case 'removeFile':
            // The file stays in the version history; it's only removed from the current set
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            $stmt = $pdo->prepare("UPDATE document_files SET IsCurrent = 0, ReplacedAt = NOW() WHERE ID = ? AND DocumentID = ? AND IsCurrent = 1");
            $stmt->execute([(int)($input['FileRowId'] ?? 0), $doc['ID']]);
            if (!$stmt->rowCount()) jsonFail(404, 'ไม่พบไฟล์');
            syncFileIdColumn($pdo, $doc['ID']);
            $review = !isAdmin() && $doc['WorkflowStatus'] === 'Approved';
            $pdo->prepare("UPDATE documents SET UpdatedAt = NOW(), RowVersion = RowVersion + 1" . ($review ? ", WorkflowStatus = 'PendingReview'" : "") . " WHERE ID = ?")
                ->execute([$doc['ID']]);
            addAuditLog($pdo, $me, 'Update Document', "Removed a file from {$doc['ID']} (kept in history)" . ($review ? ' · ส่งกลับตรวจสอบ' : ''), $doc['ID']);
            spawnBackground('extract_text.php', [$doc['ID']]);
            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $doc['ID'])]);
            break;

        case 'setWorkflow':
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            $to = $input['WorkflowStatus'] ?? '';
            if (!isset(WORKFLOW_LABELS[$to])) jsonFail(400, 'สถานะไม่ถูกต้อง');
            // Staff move drafts to review; only admins approve or send back
            $allowed = isAdmin() || (canEdit() && $to === 'PendingReview' && $doc['WorkflowStatus'] === 'Draft');
            if (!$allowed) jsonFail(403, 'ไม่มีสิทธิ์เปลี่ยนสถานะนี้');
            $pdo->prepare("UPDATE documents SET WorkflowStatus = ?, UpdatedAt = NOW(), RowVersion = RowVersion + 1 WHERE ID = ?")->execute([$to, $doc['ID']]);
            $note = trim((string)($input['Note'] ?? ''));
            addAuditLog($pdo, $me, 'Workflow', WORKFLOW_LABELS[$doc['WorkflowStatus']] . ' → ' . WORKFLOW_LABELS[$to] . ($note !== '' ? " ($note)" : ''), $doc['ID']);
            echo json_encode(['success' => true, 'updatedDoc' => publicDoc($pdo, $doc['ID'])]);
            break;

        case 'deleteDocument': // move to trash
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ลบเอกสารได้');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            $reason = mb_substr(trim((string)($input['Reason'] ?? '')), 0, 255) ?: null;
            $pdo->prepare("UPDATE documents SET DeletedAt = NOW(), DeletedBy = ?, DeleteReason = ? WHERE ID = ?")
                ->execute([$me, $reason, $doc['ID']]);
            addAuditLog($pdo, $me, "Delete Document", "Moved to trash: {$doc['ID']}" . ($reason ? " ($reason)" : ''), $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'restoreDocument':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '', true);
            $pdo->prepare("UPDATE documents SET DeletedAt = NULL, DeletedBy = NULL, DeleteReason = NULL WHERE ID = ?")->execute([$doc['ID']]);
            addAuditLog($pdo, $me, 'Restore Document', "Restored from trash: {$doc['ID']}", $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'purgeDocument':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '', true);
            if (!$doc['DeletedAt']) jsonFail(400, 'ต้องย้ายเอกสารไปถังขยะก่อนจึงจะลบถาวรได้');
            purgeDocument($pdo, $doc['ID']);
            addAuditLog($pdo, $me, 'Purge Document', "Permanently deleted: {$doc['ID']} ({$doc['Filename']})", $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'requestDestruction':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            if (!$doc['ExpiryDate'] || strtotime($doc['ExpiryDate']) >= strtotime('today')) {
                jsonFail(400, 'ขอทำลายได้เฉพาะเอกสารที่ครบอายุการเก็บแล้ว');
            }
            $reason = mb_substr(trim((string)($input['Reason'] ?? '')), 0, 255) ?: 'ครบอายุการเก็บรักษา';
            $pdo->prepare("UPDATE documents SET DestructionRequestedAt = NOW(), DestructionRequestedBy = ?, DestructionReason = ? WHERE ID = ?")
                ->execute([$me, $reason, $doc['ID']]);
            addAuditLog($pdo, $me, 'Request Destruction', "Requested destruction of {$doc['ID']}: $reason", $doc['ID']);
            echo json_encode(['success' => true]);
            break;

        case 'decideDestruction':
            if (!isAdmin()) jsonFail(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            if (!$doc['DestructionRequestedAt']) jsonFail(400, 'เอกสารนี้ไม่มีคำขอทำลาย');
            if (!empty($input['approve'])) {
                $pdo->prepare("UPDATE documents SET DeletedAt = NOW(), DeletedBy = ?, DeleteReason = ? WHERE ID = ?")
                    ->execute([$me, 'อนุมัติทำลาย: ' . $doc['DestructionReason'], $doc['ID']]);
                addAuditLog($pdo, $me, 'Approve Destruction', "Approved destruction of {$doc['ID']} (moved to trash)", $doc['ID']);
            } else {
                $pdo->prepare("UPDATE documents SET DestructionRequestedAt = NULL, DestructionRequestedBy = NULL, DestructionReason = NULL WHERE ID = ?")
                    ->execute([$doc['ID']]);
                addAuditLog($pdo, $me, 'Reject Destruction', "Rejected destruction request for {$doc['ID']}", $doc['ID']);
            }
            echo json_encode(['success' => true]);
            break;

        case 'reindexText':
            if (!canEdit()) jsonFail(403, 'บัญชีนี้มีสิทธิ์ดูเอกสารเท่านั้น');
            $doc = loadDoc($pdo, $input['ID'] ?? '');
            spawnBackground('extract_text.php', [$doc['ID']]);
            echo json_encode(['success' => true]);
            break;

        case 'getNextId':
            $prefix = (string)($input['Prefix'] ?? '');
            if ($prefix === '') jsonFail(400, 'Prefix is required');
            echo json_encode(['success' => true, 'nextNumber' => sprintf("%06d", peekNextNumber($pdo, $prefix))]);
            break;

        default:
            jsonFail(400, 'Invalid action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('document.php: ' . $e->getMessage());
    jsonFail(500, 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ');
}
