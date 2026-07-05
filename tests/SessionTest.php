<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Session;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The Session facade in isolation, against an in-memory SessionStore double —
 * no filesystem involved (see FileSessionStoreTest for the store itself).
 */
final class SessionTest extends TestCase
{
    public function testUntouchedSessionIsNeverDirtyAndSavesNothing(): void
    {
        $session = Session::fromCookie(
            new InMemorySessionStore(),
            new SequentialIdGenerator(),
            3600,
            null,
        );

        self::assertNull($session->get('missing'));
        self::assertSame('fallback', $session->get('missing', 'fallback'));
        self::assertFalse($session->isDirty());
        self::assertNull($session->save());
    }

    public function testSetActivatesTheSessionAndSaveIssuesAnId(): void
    {
        $store = new InMemorySessionStore();
        $session = Session::fromCookie($store, new SequentialIdGenerator(), 3600, null);

        $session->set('user_id', 42);
        self::assertTrue($session->isDirty());

        $id = $session->save();

        self::assertNotNull($id);
        self::assertSame(['user_id' => 42], $store->read($id));
    }

    public function testTheReservedFlashKeyCannotBeSetAsRegularData(): void
    {
        $session = Session::fromCookie(
            new InMemorySessionStore(),
            new SequentialIdGenerator(),
            3600,
            null,
        );

        $this->expectException(InvalidArgumentException::class);
        $session->set('_flash', 'lost on next load');
    }

    public function testAStoredNullWinsOverTheDefault(): void
    {
        $session = Session::fromCookie(
            new InMemorySessionStore(),
            new SequentialIdGenerator(),
            3600,
            null,
        );

        $session->set('optional', null);

        self::assertTrue($session->has('optional'));
        self::assertNull($session->get('optional', 'fallback'));
    }

    public function testAnExistingCookieIdIsTrustedAndItsDataLoaded(): void
    {
        $store = new InMemorySessionStore();
        $store->write('known-id', ['user_id' => 7], 3600);

        $session = Session::fromCookie($store, new SequentialIdGenerator(), 3600, 'known-id');

        self::assertSame(7, $session->get('user_id'));
        self::assertFalse($session->isDirty(), 'reading alone must not activate the session');
    }

    public function testAnUnknownCookieIdIsNeverAdoptedPreventingFixation(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $session = Session::fromCookie($store, $generator, 3600, 'attacker-planted-id');
        self::assertNull($session->get('anything'));

        $session->set('user_id', 1);
        $id = $session->save();

        // A brand-new id was minted; the attacker-supplied one was never used.
        self::assertNotSame('attacker-planted-id', $id);
        self::assertFalse($store->exists('attacker-planted-id'));
    }

    public function testRemoveOnlyActivatesWhenTheKeyActuallyExisted(): void
    {
        $session = Session::fromCookie(
            new InMemorySessionStore(),
            new SequentialIdGenerator(),
            3600,
            null,
        );

        $session->remove('missing');
        self::assertFalse($session->isDirty());

        $session->set('present', true);
        $session->save();
        self::assertTrue($session->isDirty());
    }

    public function testFlashIsNotReadableUntilTheFollowingLoad(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->flash('notice', 'Saved!');

        // Not readable in the same request/session instance it was flashed in.
        self::assertNull($first->consumeFlash('notice'));

        $id = $first->save();
        self::assertNotNull($id);

        $second = Session::fromCookie($store, $generator, 3600, $id);
        self::assertTrue($second->hasFlash('notice'));
        self::assertSame('Saved!', $second->consumeFlash('notice'));

        // Consumed once: a repeat read in the same load yields the default.
        self::assertNull($second->consumeFlash('notice'));
    }

    public function testFlashDoesNotSurviveASecondLoadEvenOnAReadOnlyRequest(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->flash('notice', 'Saved!');
        $id = $first->save();

        // The request that loads the flash writes nothing itself — the
        // typical PRG landing page. Loading the flash is what expires it:
        // the session marks itself dirty so save() rewrites the store entry
        // without the flash, whether or not anything consumed it.
        $second = Session::fromCookie($store, $generator, 3600, $id);
        self::assertTrue($second->isDirty(), 'loading a flash must schedule the aging write');
        $second->save();

        $third = Session::fromCookie($store, $generator, 3600, $id);
        self::assertFalse($third->hasFlash('notice'));
    }

    public function testAConsumedFlashDoesNotReappearOnRefresh(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->flash('notice', 'Saved!');
        $id = $first->save();

        // Landing page: consumes the flash, renders, sets nothing.
        $second = Session::fromCookie($store, $generator, 3600, $id);
        self::assertSame('Saved!', $second->consumeFlash('notice'));
        $second->save();

        // Refreshing the landing page must not show the notice again.
        $third = Session::fromCookie($store, $generator, 3600, $id);
        self::assertFalse($third->hasFlash('notice'));
        self::assertNull($third->consumeFlash('notice'));
    }

    public function testLoadingASessionWithoutFlashStaysClean(): void
    {
        $store = new InMemorySessionStore();
        $store->write('known-id', ['user_id' => 7], 3600);

        $session = Session::fromCookie($store, new SequentialIdGenerator(), 3600, 'known-id');

        self::assertFalse($session->isDirty(), 'no flash to age — no write should be scheduled');
        self::assertNull($session->save());
    }

    public function testFlashDataIsNotExposedAsRegularData(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->flash('notice', 'Saved!');
        $id = $first->save();

        $second = Session::fromCookie($store, $generator, 3600, $id);
        self::assertNull($second->get('notice'));
        self::assertFalse($second->has('notice'));
    }

    public function testRegenerateKeepsDataButIssuesAFreshIdAndDropsTheOld(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->set('user_id', 1);
        $originalId = $first->save();
        self::assertNotNull($originalId);

        $session = Session::fromCookie($store, $generator, 3600, $originalId);
        $session->regenerate();
        $newId = $session->save();

        self::assertNotSame($originalId, $newId);
        self::assertFalse($store->exists((string) $originalId));
        self::assertSame(['user_id' => 1], $store->read((string) $newId));
    }

    public function testDestroyClearsDataAndSuppressesTheCookie(): void
    {
        $store = new InMemorySessionStore();
        $generator = new SequentialIdGenerator();

        $first = Session::fromCookie($store, $generator, 3600, null);
        $first->set('user_id', 1);
        $id = $first->save();
        self::assertNotNull($id);

        $session = Session::fromCookie($store, $generator, 3600, $id);
        $session->destroy();

        self::assertTrue($session->wasDestroyed());
        self::assertNull($session->save());
        self::assertFalse($store->exists((string) $id));
        self::assertNull($session->get('user_id'));
    }

    public function testSaveIsIdempotent(): void
    {
        $store = new InMemorySessionStore();
        $session = Session::fromCookie($store, new SequentialIdGenerator(), 3600, null);

        $session->set('a', 1);
        $id = $session->save();
        $again = $session->save();

        self::assertSame($id, $again);
    }
}
