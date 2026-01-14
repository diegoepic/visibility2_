<?php
declare(strict_types=1);

function remember_cookie_name(): string {
  return 'v2_remember';
}

function remember_cookie_params(): array {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  return [
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function ensure_remember_table(mysqli $conn): void {
  $conn->query("
    CREATE TABLE IF NOT EXISTS user_remember_tokens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      selector VARCHAR(32) NOT NULL,
      token_hash CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL,
      last_used_at DATETIME NULL,
      revoked_at DATETIME NULL,
      ip VARBINARY(16) NULL,
      user_agent VARCHAR(255) NULL,
      UNIQUE KEY uniq_selector (selector),
      KEY idx_user (user_id),
      KEY idx_revoked (revoked_at)
    ) ENGINE=InnoDB
  ");
}

function generate_remember_pair(): array {
  $selector = bin2hex(random_bytes(8));
  $token = bin2hex(random_bytes(32));
  return [
    'selector' => $selector,
    'token' => $token,
    'hash' => hash('sha256', $token)
  ];
}

function set_remember_cookie(string $selector, string $token, int $days = 30): void {
  $params = remember_cookie_params();
  $expires = time() + ($days * 86400);
  setcookie(
    remember_cookie_name(),
    $selector . ':' . $token,
    $expires,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
  );
}

function clear_remember_cookie(): void {
  $params = remember_cookie_params();
  setcookie(
    remember_cookie_name(),
    '',
    time() - 42000,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
  );
}

function parse_remember_cookie(): ?array {
  $raw = $_COOKIE[remember_cookie_name()] ?? '';
  if (!$raw || !is_string($raw)) return null;
  $parts = explode(':', $raw, 2);
  if (count($parts) !== 2) return null;
  [$selector, $token] = $parts;
  if ($selector === '' || $token === '') return null;
  return ['selector' => $selector, 'token' => $token];
}

function remember_store_token(mysqli $conn, int $userId, string $selector, string $tokenHash, ?string $ip, ?string $ua): void {
  ensure_remember_table($conn);
  if ($st = $conn->prepare("
    INSERT INTO user_remember_tokens (user_id, selector, token_hash, created_at, last_used_at, ip, user_agent)
    VALUES (?, ?, ?, NOW(), NOW(), INET6_ATON(?), ?)
  ")) {
    $ipVal = $ip ?? '0.0.0.0';
    $uaVal = $ua ?? 'unknown';
    $st->bind_param("issss", $userId, $selector, $tokenHash, $ipVal, $uaVal);
    $st->execute();
    $st->close();
  }
}

function remember_revoke_token(mysqli $conn, string $selector): void {
  ensure_remember_table($conn);
  if ($st = $conn->prepare("UPDATE user_remember_tokens SET revoked_at = NOW() WHERE selector = ? AND revoked_at IS NULL")) {
    $st->bind_param("s", $selector);
    $st->execute();
    $st->close();
  }
}

function remember_revoke_other_tokens(mysqli $conn, int $userId, string $currentSelector): void {
  ensure_remember_table($conn);
  if ($st = $conn->prepare("UPDATE user_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND selector <> ? AND revoked_at IS NULL")) {
    $st->bind_param("is", $userId, $currentSelector);
    $st->execute();
    $st->close();
  }
}

function remember_find_token(mysqli $conn, string $selector): ?array {
  ensure_remember_table($conn);
  if ($st = $conn->prepare("
    SELECT user_id, token_hash, revoked_at
    FROM user_remember_tokens
    WHERE selector = ?
    LIMIT 1
  ")) {
    $st->bind_param("s", $selector);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    if (!$row) return null;
    return [
      'user_id' => (int)$row['user_id'],
      'token_hash' => (string)$row['token_hash'],
      'revoked_at' => $row['revoked_at']
    ];
  }
  return null;
}

function remember_update_token(mysqli $conn, string $selector, string $tokenHash): void {
  ensure_remember_table($conn);
  if ($st = $conn->prepare("
    UPDATE user_remember_tokens
    SET token_hash = ?, last_used_at = NOW()
    WHERE selector = ? AND revoked_at IS NULL
  ")) {
    $st->bind_param("ss", $tokenHash, $selector);
    $st->execute();
    $st->close();
  }
}