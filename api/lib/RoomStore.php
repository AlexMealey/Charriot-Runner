<?php

declare(strict_types=1);

/**
 * On-disk JSON store for races and rooms.
 *
 * Layout under <project>/data/:
 *   <kind>/<id>.json                     — one document per id
 *   <kind>/<id>/race-<n>.ticks.jsonl.gz  — gzipped tick history per race
 *
 * $kind is "practice" or "rooms". Every write serializes through an flock
 * sidecar and lands via temp-file-then-rename, so back-to-back (or
 * concurrent) writes never expose a partial or empty file — readers see the
 * old document or the new one, never a blend.
 */
class RoomStore
{
    /** Write a document atomically. */
    public static function put(string $kind, string $id, array $doc): void
    {
        if (!self::validId($id)) {
            return;
        }
        $dest = self::root() . '/' . $kind . '/' . $id . '.json';
        self::ensureDir(dirname($dest));

        $lock = @fopen($dest . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }
        self::writeDoc($dest, $doc);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Read → modify → write under one lock: $fn receives the stored document
     * and returns the document to persist. History appends made inside $fn
     * share the sidecar lock, so concurrent polls can neither interleave
     * tick lines nor lose each other's steps (code review C1). Returns the
     * persisted document, or null when the id is unknown, the file is
     * unreadable, or the lock cannot be taken. Never calls put() — a second
     * handle on the same sidecar would deadlock against our own lock.
     */
    public static function mutate(string $kind, string $id, callable $fn): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        $dest = self::root() . '/' . $kind . '/' . $id . '.json';
        if (!is_file($dest)) {
            return null;
        }
        self::ensureDir(dirname($dest));

        $lock = @fopen($dest . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return null;
        }
        $doc = json_decode((string) @file_get_contents($dest), true);
        $out = null;
        if (is_array($doc)) {
            $changed = $fn($doc);
            if (is_array($changed)) {
                self::writeDoc($dest, $changed);
                $out = $changed;
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return $out;
    }

    /** Read a document; null when missing or unreadable. */
    public static function get(string $kind, string $id): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        $path = self::root() . '/' . $kind . '/' . $id . '.json';
        if (!is_file($path)) {
            return null;
        }
        $doc = json_decode((string) file_get_contents($path), true);
        return is_array($doc) ? $doc : null;
    }

    /** Append tick lines (one JSON string per line) to a race's history. */
    public static function appendTicks(string $kind, string $id, int $raceNo, array $lines): void
    {
        if ($lines === [] || !self::validId($id)) {
            return;
        }
        $dir = self::root() . '/' . $kind . '/' . $id;
        self::ensureDir($dir);
        $gz = @gzopen($dir . '/race-' . $raceNo . '.ticks.jsonl.gz', 'a9');
        if ($gz === false) {
            return;
        }
        foreach ($lines as $line) {
            gzwrite($gz, $line . "\n");
        }
        gzclose($gz);
    }

    /** Build a fresh lobby room document (spec §4); persist via saveRoom(). */
    public static function createRoom(array $cfg): array
    {
        $now = date(DATE_ATOM);
        return [
            'id' => bin2hex(random_bytes(16)),
            'createdAt' => $now,
            'updatedAt' => $now,
            'hostToken' => bin2hex(random_bytes(32)),
            'config' => $cfg,
            'status' => 'lobby',
            'participants' => [],
            'raceNo' => 0,
            'race' => null,
            'history' => [],
        ];
    }

    /** Load a room document; null when the id is malformed or unknown. */
    public static function getRoom(string $id): ?array
    {
        return self::get('rooms', $id);
    }

    /** Persist a room document (atomic, see put()). */
    public static function saveRoom(array $doc): void
    {
        self::put('rooms', $doc['id'], $doc);
    }

    /** Temp-file-then-rename swap; the caller holds the sidecar lock. */
    private static function writeDoc(string $dest, array $doc): void
    {
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return; // keep the previous file rather than store garbage
        }
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json) === strlen($json)) {
            rename($tmp, $dest); // atomic swap: readers see old or new
        } else {
            @unlink($tmp);
        }
    }

    /** Root of the store, one level above api/. */
    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/data';
    }

    /** mkdir that tolerates a concurrent creator (no warnings in JSON). */
    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /** Ids are hex (spec §2); anything else never touches the disk path. */
    private static function validId(string $id): bool
    {
        return preg_match('/^[0-9a-f]+$/', $id) === 1;
    }
}
