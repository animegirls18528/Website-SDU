<?php
/**
 * Index the text of a document's files for full-text search.
 *   php tools/extract_text.php <documentId>     one document
 *   php tools/extract_text.php --all            every document that has files but no text yet
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/storage.php';

ini_set('memory_limit', '1024M');
set_time_limit(0);

$arg = $argv[1] ?? '';
if ($arg === '--all') {
    $ids = $pdo->query("SELECT DISTINCT d.ID FROM documents d JOIN document_files f ON f.DocumentID = d.ID AND f.IsCurrent = 1
                        WHERE d.OcrText IS NULL")->fetchAll(PDO::FETCH_COLUMN);
} elseif ($arg !== '') {
    $ids = [$arg];
} else {
    fwrite(STDERR, "usage: php tools/extract_text.php <documentId>|--all\n");
    exit(1);
}

foreach ($ids as $id) {
    $chars = indexDocumentText($pdo, $id);
    echo "$id: $chars chars\n";
}
