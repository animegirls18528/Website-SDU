<?php
/**
 * PHP sessions stored in MySQL, with who/where metadata, so a user (or an admin) can list active logins
 * and sign a device out. Also works on hosts with no shared disk.
 */
class DbSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    #[\ReturnTypeWillChange]
    public function read($id) {
        $stmt = $this->pdo->prepare("SELECT Data FROM sessions WHERE ID = ? AND UpdatedAt > ?");
        $stmt->execute([$id, time() - (int)ini_get('session.gc_maxlifetime')]);
        $data = $stmt->fetchColumn();
        return $data === false ? '' : $data;
    }

    public function write($id, $data): bool {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $this->pdo->prepare("INSERT INTO sessions (ID, Data, UpdatedAt, UserID, IP, UserAgent, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE Data = VALUES(Data), UpdatedAt = VALUES(UpdatedAt), UserID = VALUES(UserID),
                                                     IP = VALUES(IP), UserAgent = VALUES(UserAgent)")
            ->execute([$id, $data, time(), $userId, $ip, $ua, time()]);
        return true;
    }

    public function destroy($id): bool {
        $this->pdo->prepare("DELETE FROM sessions WHERE ID = ?")->execute([$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($max) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE UpdatedAt < ?");
        $stmt->execute([time() - $max]);
        return $stmt->rowCount();
    }
}

/** v2 always keeps sessions in the database (needed for the "active devices" list). */
function useDatabaseSessions() {
    return true;
}

/** HTTPS as seen by the browser, also when a proxy terminates TLS. */
function requestIsHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
