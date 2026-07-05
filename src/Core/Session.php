<?php

declare(strict_types=1);

namespace Garner\Core;

use Garner\Support\IdGenerator;
use InvalidArgumentException;

/**
 * Per-visitor key/value state, backed by a pluggable SessionStore. Obtained
 * via Application::session(); a generic primitive (not a "logged-in user"
 * concept) that a future auth feature would build on rather than duplicate.
 *
 * Activation is lazy and explicit: constructing a Session only reads an
 * already-existing store entry (if the incoming cookie names one Garner
 * issued — see fromCookie()). Nothing is written, and no cookie is ever
 * sent, until set(), flash(), or destroy() is called — with one exception:
 * a session loaded *with* flash data pending is saved once more even if
 * the request changes nothing (see fromCookie()), so a flash ages out
 * after exactly one request. A page whose visitor has no session stays
 * exactly as stateless and cache-friendly as today.
 * Application::attachSessionCookie() calls save() once per request — from
 * Router::emit() or ErrorHandler::emit(), whichever ends the request — to
 * persist changes and learn the cookie value (if any) to attach to the
 * response.
 */
final class Session
{
    private const string FLASH_KEY = '_flash';

    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * Flash values readable this request (flashed on the previous one).
     *
     * @var array<string, mixed>
     */
    private array $flash;

    /**
     * Flash values flashed this request, readable on the next one only.
     *
     * @var array<string, mixed>
     */
    private array $pendingFlash = [];

    private bool $dirty = false;
    private bool $destroyed = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $flash
     */
    private function __construct(
        private readonly SessionStore $store,
        private readonly IdGenerator $idGenerator,
        private readonly int $lifetime,
        private ?string $id,
        array $data,
        array $flash,
    ) {
        $this->data = $data;
        $this->flash = $flash;
    }

    /**
     * Build a session bound to an incoming cookie value, if any. $cookieId
     * is only trusted — and its data loaded — when the store recognizes it
     * as a session Garner itself issued (SessionStore::exists()); an
     * unknown, expired, or tampered id is discarded rather than adopted, so
     * a client cannot plant an id and have Garner start writing data under
     * it (session fixation).
     */
    public static function fromCookie(
        SessionStore $store,
        IdGenerator $idGenerator,
        int $lifetime,
        ?string $cookieId,
    ): self {
        $id = $cookieId !== null && $cookieId !== '' && $store->exists($cookieId)
            ? $cookieId
            : null;
        $stored = $id !== null ? $store->read($id) : [];

        $flash = $stored[self::FLASH_KEY] ?? [];
        unset($stored[self::FLASH_KEY]);

        $session = new self(
            $store,
            $idGenerator,
            $lifetime,
            $id,
            $stored,
            is_array($flash) ? $flash : [],
        );

        // Aging write: loading flash data is what expires it, so the store
        // entry must be rewritten (without the flash) even if this request
        // changes nothing else. Otherwise a read-only request — the typical
        // PRG landing page — would leave the flash in the store, and it
        // would resurface on every subsequent load until something happened
        // to write. The visitor already has a session cookie at this point,
        // so this write costs no statelessness.
        if ($session->flash !== []) {
            $session->dirty = true;
        }

        return $session;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // array_key_exists, not ??: a stored null is a present value (has()
        // agrees), so it must win over $default.
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        // "_flash" is where save() nests pending flash values inside the
        // store payload; a user value under that key would be stripped as
        // flash metadata on the next load (or overwritten by pending
        // flashes), so it silently could not round-trip. Reject it loudly
        // instead.
        if ($key === self::FLASH_KEY) {
            throw new InvalidArgumentException(sprintf(
                'The session key "%s" is reserved for flash metadata; use flash() instead',
                self::FLASH_KEY,
            ));
        }

        $this->data[$key] = $value;
        $this->activate();
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->activate();
        }
    }

    /**
     * Store a value readable on the *next* request only, via consumeFlash().
     * The motivating case: an action flashes a message before redirecting
     * (Post/Redirect/Get), and the page it lands on reads it once.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->pendingFlash[$key] = $value;
        $this->activate();
    }

    /**
     * Read a value flashed on the previous request and clear it, so a
     * repeat call in the same request yields $default. A flashed value is
     * available for exactly one request after being set, whether or not
     * it's ever consumed — it is never re-persisted from here.
     */
    public function consumeFlash(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->flash)) {
            return $default;
        }

        $value = $this->flash[$key];
        unset($this->flash[$key]);

        return $value;
    }

    /**
     * Whether a value is currently flashed for this request (without
     * consuming it).
     */
    public function hasFlash(string $key): bool
    {
        return array_key_exists($key, $this->flash);
    }

    /**
     * Rotate the session id, keeping its data. Call this the moment a
     * session's privilege changes (e.g. right after a successful login) to
     * prevent session fixation: an id an attacker may have planted before
     * authentication stops being valid once the session becomes worth
     * hijacking.
     */
    public function regenerate(): void
    {
        if ($this->id !== null) {
            $this->store->destroy($this->id);
        }

        $this->id = null;
        $this->activate();
    }

    /**
     * Discard the session entirely: its store entry (if any) and all data.
     * The response should expire the session cookie —
     * Application::attachSessionCookie() does this by checking
     * wasDestroyed().
     */
    public function destroy(): void
    {
        if ($this->id !== null) {
            $this->store->destroy($this->id);
        }

        $this->id = null;
        $this->data = [];
        $this->flash = [];
        $this->pendingFlash = [];
        $this->dirty = false;
        $this->destroyed = true;
    }

    public function wasDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * Whether anything has changed this request (set/remove/flash/destroy
     * were called). Router uses this to decide whether save() has anything
     * worth persisting — an untouched session writes nothing and sends no
     * cookie.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Persist pending changes, if any, and return the session id to send as
     * the cookie value. Returns null when there is nothing to persist
     * (untouched session, or destroy() was called — see wasDestroyed()).
     * Idempotent: safe to call more than once.
     */
    public function save(): ?string
    {
        if ($this->destroyed || !$this->dirty) {
            return null;
        }

        $this->id ??= $this->idGenerator->generate();

        $payload = $this->pendingFlash === []
            ? $this->data
            : [...$this->data, self::FLASH_KEY => $this->pendingFlash];

        $this->store->write($this->id, $payload, $this->lifetime);

        return $this->id;
    }

    private function activate(): void
    {
        $this->dirty = true;
        $this->destroyed = false;
    }
}
