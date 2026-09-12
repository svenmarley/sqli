<?php

declare(strict_types=1);

namespace Sqli\Db;

use PDO;

/**
 * TLS settings for the MySQL connection (spec §2, "Encrypted connections").
 *
 * Pure value object: it validates the requested mode, produces the PDO
 * attribute array, and answers the two policy questions {@see Database} needs
 * — "must this connection be encrypted?" and "may I retry without TLS?". It
 * performs no I/O beyond checking that the certificate files it was given
 * exist, so the whole mapping can be pinned headlessly.
 *
 * Modes mirror the `mysql` client's `--ssl-mode`, because that is the
 * vocabulary anyone configuring a MySQL server already has:
 *
 *   disabled         never offer TLS (sqli's behaviour before 2.32.0)
 *   preferred        use TLS when the server supports it, plaintext when not
 *   required         TLS or fail; the server certificate is not checked
 *   verify-ca        TLS, and the server certificate must chain to `ssl-ca`
 *   verify-identity  verify-ca, plus the host name must match the certificate
 *
 * **Why `preferred` cannot be trusted to fall back silently.** PHP's mysqlnd
 * driver has no "try TLS, quietly downgrade" mode of its own: once the SSL
 * client flag is set the handshake either completes or the connection fails.
 * So the fallback is ours — {@see isTlsUnavailable()} recognises the failures
 * that mean "this server does not do TLS" and only those, which is what keeps
 * a wrong password from being retried into a confusing second error.
 */
final class TlsOptions
{
    public const DISABLED        = 'disabled';
    public const PREFERRED       = 'preferred';
    public const REQUIRED        = 'required';
    public const VERIFY_CA       = 'verify-ca';
    public const VERIFY_IDENTITY = 'verify-identity';

    /** @var list<string> */
    public const MODES = [
        self::DISABLED,
        self::PREFERRED,
        self::REQUIRED,
        self::VERIFY_CA,
        self::VERIFY_IDENTITY,
    ];

    /**
     * Cipher list sent purely to **arm** the driver when no other certificate
     * option would.
     *
     * PHP's pdo_mysql only calls `mysql_ssl_set()` — the call that sets the
     * `CLIENT_SSL` capability bit — when at least one of SSL_KEY / SSL_CERT /
     * SSL_CA / SSL_CAPATH / SSL_CIPHER is present.
     * `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` is handled on a separate path and does
     * **not** arm it: asking for `required` with no CA file produced a plaintext
     * handshake, which is how sqli 2.32.0 still hit error 3159 on a
     * `require_secure_transport=ON` server. Confirmed on the wire — see the
     * capability-flag probe in `tests/tls_test.php`.
     *
     * `DEFAULT` is OpenSSL's own default selection, so it constrains nothing
     * that was not already constrained, and it governs TLS 1.2 and below only —
     * TLS 1.3 suites are configured through a separate API and are untouched.
     */
    private const ARMING_CIPHER = 'DEFAULT';

    /**
     * MySQL client error codes that mean the *transport* could not be
     * established as TLS, as opposed to anything about who you are or what you
     * asked for. 2026 is the generic "SSL connection error"; 2006/2013 appear
     * when a server that speaks no TLS drops the connection mid-handshake.
     */
    private const TLS_ERROR_CODES = [2026, 2006, 2013];

    private function __construct(
        public readonly string $mode,
        public readonly string $ca,
        public readonly string $cert,
        public readonly string $key,
        public readonly string $cipher,
        public readonly string $caPath = '',
        public readonly string $serverPublicKey = '',
    ) {
    }

    /** The pre-2.32.0 behaviour: a plaintext connection, every time. */
    public static function disabled(): self
    {
        return new self(self::DISABLED, '', '', '', '');
    }

    /**
     * Build from resolved config values. An unrecognised mode is **not**
     * silently downgraded — it lands in $errors, because quietly treating
     * `ssl-mode: requred` as "no TLS" is exactly the failure the user would
     * never notice.
     *
     * @param list<string> $errors filled with human-readable problems; the
     *        returned object is still usable (worst case, TLS disabled) so the
     *        caller decides whether to refuse to start.
     */
    public static function fromValues(
        string $mode,
        string $ca = '',
        string $cert = '',
        string $key = '',
        string $cipher = '',
        array &$errors = [],
        string $caPath = '',
        string $serverPublicKey = '',
    ): self {
        $mode = strtolower(trim($mode));
        $mode = str_replace('_', '-', $mode);
        if ($mode === '') {
            $mode = self::PREFERRED;
        }
        if (!in_array($mode, self::MODES, true)) {
            $errors[] = "ssl-mode: unknown value '$mode' (expected " . implode(', ', self::MODES) . ')';
            $mode = self::PREFERRED;
        }

        $opts = new self(
            $mode, trim($ca), trim($cert), trim($key), trim($cipher),
            trim($caPath), trim($serverPublicKey),
        );

        // A client certificate is a pair. Half of one is always a mistake, and
        // the server's rejection ("Access denied") would not say so.
        if ($opts->cert !== '' && $opts->key === '') {
            $errors[] = 'ssl-cert given without ssl-key — a client certificate needs both';
        }
        if ($opts->key !== '' && $opts->cert === '') {
            $errors[] = 'ssl-key given without ssl-cert — a client certificate needs both';
        }
        if ($opts->verifiesCertificate() && $opts->ca === '' && $opts->caPath === '') {
            $errors[] = "ssl-mode '$opts->mode' needs ssl-ca (or ssl-capath) — "
                . 'there is nothing to verify the server against';
        }
        foreach ([
            'ssl-ca'            => $opts->ca,
            'ssl-cert'          => $opts->cert,
            'ssl-key'           => $opts->key,
            'server-public-key' => $opts->serverPublicKey,
        ] as $label => $path) {
            if ($path !== '' && !is_file($path)) {
                $errors[] = "$label: no such file — $path";
            }
        }
        if ($opts->caPath !== '' && !is_dir($opts->caPath)) {
            $errors[] = "ssl-capath: not a directory — $opts->caPath";
        }
        // Certificate files are pointless without TLS, and saying so is kinder
        // than connecting in plaintext while the config looks encrypted.
        if ($opts->mode === self::DISABLED
            && ($opts->ca !== '' || $opts->caPath !== '' || $opts->cert !== '' || $opts->cipher !== '')) {
            $errors[] = "ssl-mode is 'disabled' but certificate options were given — they are ignored";
        }

        return $opts;
    }

    /** True when the connection should carry the SSL client flag at all. */
    public function wantsTls(): bool
    {
        return $this->mode !== self::DISABLED;
    }

    /**
     * True when a plaintext connection is a failure rather than a fallback.
     * {@see Database} enforces this *after* connecting, by reading the session's
     * cipher — the only statement that survives a driver whose SSL flag didn't
     * take effect for a reason we can't see from here.
     */
    public function requiresTls(): bool
    {
        return $this->mode !== self::DISABLED && $this->mode !== self::PREFERRED;
    }

    /** True when the server's certificate must be checked against the CA. */
    public function verifiesCertificate(): bool
    {
        return $this->mode === self::VERIFY_CA || $this->mode === self::VERIFY_IDENTITY;
    }

    /**
     * PDO attributes for {@see \PDO::__construct}. Empty when TLS is disabled,
     * so the connection is byte-for-byte what sqli built before 2.32.0.
     *
     * `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` is emitted for every TLS mode, true or
     * false — but it is never what turns TLS *on*. Something from the
     * certificate group has to be present for that, so when the user configured
     * none of them we supply {@see ARMING_CIPHER}.
     *
     * @return array<int,mixed>
     */
    public function pdoAttributes(): array
    {
        // Not a TLS option: it is how `caching_sha2_password` completes over an
        // *unencrypted* link, so it has to survive ssl-mode=disabled — that is
        // the only situation in which it does anything at all.
        $publicKey = $this->serverPublicKey !== ''
            ? [PDO::MYSQL_ATTR_SERVER_PUBLIC_KEY => $this->serverPublicKey]
            : [];

        if (!$this->wantsTls()) {
            return $publicKey;
        }
        $attrs = $publicKey + [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $this->verifiesCertificate()];
        if ($this->ca !== '') {
            $attrs[PDO::MYSQL_ATTR_SSL_CA] = $this->ca;
        }
        if ($this->cert !== '') {
            $attrs[PDO::MYSQL_ATTR_SSL_CERT] = $this->cert;
        }
        if ($this->key !== '') {
            $attrs[PDO::MYSQL_ATTR_SSL_KEY] = $this->key;
        }
        if ($this->caPath !== '') {
            $attrs[PDO::MYSQL_ATTR_SSL_CAPATH] = $this->caPath;
        }
        $cipher = $this->cipher;
        if ($cipher === '' && $this->ca === '' && $this->caPath === '') {
            // Nothing else here would reach mysql_ssl_set(), so the connection
            // would go out in plaintext however emphatically the mode was named.
            $cipher = self::ARMING_CIPHER;
        }
        $attrs[PDO::MYSQL_ATTR_SSL_CIPHER] = $cipher;
        return $attrs;
    }

    /**
     * The certificate paths for `mysqli::ssl_set()`, in its argument order:
     * key, cert, ca, capath, cipher. Empty strings become null, which is what
     * that call means by "not given".
     *
     * {@see ARMING_CIPHER} is deliberately absent. It exists because pdo_mysql
     * only reaches `mysql_ssl_set()` when one of the certificate options is
     * present, so a `required` with no CA file went out in plaintext. mysqli has
     * no such gap — {@see mysqliFlags()} sets `CLIENT_SSL` itself — so supplying
     * a cipher list nobody asked for would constrain the handshake for no
     * reason. The two drivers are armed differently because they *are* different;
     * what makes both safe is the session cipher read back afterwards.
     *
     * @return array{0:?string,1:?string,2:?string,3:?string,4:?string}
     */
    public function mysqliSslSet(): array
    {
        $orNull = static fn (string $v): ?string => $v !== '' ? $v : null;
        return [
            $orNull($this->key),
            $orNull($this->cert),
            $orNull($this->ca),
            $orNull($this->caPath),
            $orNull($this->cipher),
        ];
    }

    /**
     * TLS connect flags for {@see MysqliLink}. 0 when TLS is disabled, so the
     * connection is what sqli built before 2.32.0.
     *
     * `CLIENT_SSL` is what arms the handshake. `SSL_DONT_VERIFY_SERVER_CERT` is
     * then added for the modes that do not check the certificate — `required`
     * asks for encryption and says nothing about identity, and without this flag
     * mysqlnd would refuse a self-signed server that `required` is supposed to
     * accept.
     *
     * Returns 0 when the extension is absent, so this stays callable on a build
     * with no mysqli to name the constants.
     */
    public function mysqliFlags(): int
    {
        if (!$this->wantsTls() || !extension_loaded('mysqli')) {
            return 0;
        }
        $flags = MYSQLI_CLIENT_SSL;
        if (!$this->verifiesCertificate()) {
            $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
        }
        return $flags;
    }

    /**
     * Should a failed TLS connection be retried in plaintext? Only under
     * `preferred`, and only for failures that actually describe the transport.
     * An authentication failure must surface as itself: retrying it without TLS
     * would report whatever the plaintext attempt hit (very likely error 3159,
     * "insecure transport prohibited") and send the reader hunting a
     * certificate problem that does not exist.
     */
    public function shouldRetryPlaintext(int $code, string $message): bool
    {
        return $this->mode === self::PREFERRED && self::isTlsUnavailable($code, $message);
    }

    /**
     * Does this connection error mean "TLS is not available here"?
     *
     * Two different unavailabilities, and both belong in `preferred`'s fallback:
     * a *server* that does not offer TLS, and a *transport* PHP cannot encrypt.
     * The second is the Unix socket — PHP's `unix://` stream has no crypto layer,
     * so arming TLS over one fails before the server is ever asked, with a
     * `This stream does not support SSL/crypto` warning and mysqlnd's
     * "Cannot connect to MySQL using SSL". That arrives as error **2002**, the
     * same number a server that is not listening produces, so it is matched on
     * the message and 2002 is deliberately *not* in {@see TLS_ERROR_CODES}:
     * adding it would turn every "connection refused" into a silent second
     * attempt. Database::connect() avoids the attempt altogether under
     * `preferred` — this is the net under it.
     */
    public static function isTlsUnavailable(int $code, string $message): bool
    {
        if (in_array($code, self::TLS_ERROR_CODES, true)) {
            return true;
        }
        $m = strtolower($message);
        return str_contains($m, 'ssl connection error')
            || str_contains($m, 'server doesn\'t support ssl')
            || str_contains($m, 'server does not support ssl')
            || str_contains($m, 'cannot connect to mysql using ssl')
            || str_contains($m, 'does not support ssl/crypto');
    }

    /**
     * Will this host/socket pair connect over a Unix socket rather than TCP?
     *
     * mysqlnd's rule, not ours: an explicit socket path is one, and so is the
     * host name `localhost` (or none at all) on a non-Windows build — `mysql`
     * behaves the same way, which is why `-h localhost` and `-h 127.0.0.1` are
     * two different connections to the same server. It matters here because the
     * socket cannot carry TLS at all: see {@see isTlsUnavailable()}.
     */
    public static function isSocketTransport(string $host, string $socket): bool
    {
        if (trim($socket) !== '') {
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        return $host === '' || strtolower($host) === 'localhost';
    }

    /** Short description for the status line / log, e.g. `verify-ca (ca.pem)`. */
    public function describe(): string
    {
        if (!$this->wantsTls()) {
            return self::DISABLED;
        }
        $extras = [];
        if ($this->ca !== '') {
            $extras[] = 'ca ' . basename($this->ca);
        }
        if ($this->caPath !== '') {
            $extras[] = 'ca dir ' . basename($this->caPath);
        }
        if ($this->cert !== '') {
            $extras[] = 'client cert ' . basename($this->cert);
        }
        if ($this->cipher !== '') {
            $extras[] = 'cipher ' . $this->cipher;
        }
        return $this->mode . ($extras === [] ? '' : ' (' . implode(', ', $extras) . ')');
    }
}
