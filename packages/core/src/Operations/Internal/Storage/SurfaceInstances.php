<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

/** Internal SQL primitives only: authorization/admission precede the owner transaction in P2.7.
 * Receipt -> surface -> own target lock order. Each object addresses exactly one complete identity;
 * callers handling multiple surfaces must sort installation/panel/kind/host type/key before taking locks.
 * The current row is permanent, including after deletion. Never lock a missing row to claim creation.
 */
final readonly class SurfaceInstances
{
    public function __construct(private OwnerConnection $owner) {}

    /** Explicit first creation, never an event-driven upsert. INSERT UNIQUE arbitrates absent-row races. */
    public function create(Revision $sourceGeneration, Revision $privacyGeneration): SurfaceInstance
    {
        $this->owner->requireTransaction();
        $ref = $this->owner->handle()->surface;
        if ($ref->incarnation->value !== 1) {
            $this->reject(ProblemCode::SurfaceIncarnationMismatch);
        }
        if ($this->query('tombstones')->exists()) {
            $incarnation = $this->query('surface_instances')->lockForUpdate()->value('incarnation');
            if ($incarnation !== null && $this->integer($incarnation) !== $ref->incarnation->value) {
                $this->reject(ProblemCode::SurfaceIncarnationMismatch);
            }
            $this->reject(ProblemCode::ContentDeleted);
        }
        // ON CONFLICT does not poison PostgreSQL's transaction on a concurrent unique collision.
        $inserted = $this->owner->table('surface_instances')->insertOrIgnore($this->initial(sourceGeneration: $sourceGeneration, privacyGeneration: $privacyGeneration));
        $current = $this->locked();
        if ($inserted !== 1) {
            $this->sameIncarnation($current);
            $this->reject($current->lifecycle === SurfaceLifecycle::Deleted ? ProblemCode::ContentDeleted : ProblemCode::RevisionConflict);
        }

        return $current;
    }

    /** Current snapshot under the surface lock, including deleted state for trusted envelope checks. */
    public function lock(): SurfaceInstance
    {
        $current = $this->locked();
        $this->sameIncarnation($current);

        return $current;
    }

    /** Must precede any owned-target write. Does not authorize the caller. */
    public function writable(Revision $expectedRevision): SurfaceInstance
    {
        $current = $this->lock();
        $this->active($current);
        if ($current->lifecycle === SurfaceLifecycle::Archived) {
            $this->reject(ProblemCode::SurfaceArchived);
        }
        $this->expected(current: $current, expected: $expectedRevision);

        return $current;
    }

    /** Local policy edit; archived surfaces reject writes. Owner target writes use writable() in the envelope. */
    public function revise(Revision $expectedRevision, Revision $sourceGeneration, Revision $privacyGeneration): SurfaceInstance
    {
        $current = $this->writable($expectedRevision);
        if ($sourceGeneration->value < $current->sourceGeneration->value || $privacyGeneration->value < $current->privacyGeneration->value) {
            $this->reject(ProblemCode::RevisionConflict);
        }
        $changed = $this->query('surface_instances')->where('incarnation', $current->ref->incarnation->value)->where('revision', $expectedRevision->value)
            ->update(['revision' => $current->revision->next()->value, 'source_generation' => $sourceGeneration->value, 'privacy_generation' => $privacyGeneration->value, 'updated_at' => $this->now()]);
        if ($changed !== 1) {
            $this->reject(ProblemCode::RevisionConflict);
        }

        return $this->lock();
    }

    /** Live <-> Archived and Live/Archived -> Deleted; generation regression is rejected, never retried. */
    public function transition(SurfaceLifecycle $to, Revision $expectedRevision, Revision $sourceGeneration, Revision $privacyGeneration): SurfaceInstance
    {
        $current = $this->lock();
        $this->active($current);
        $this->expected(current: $current, expected: $expectedRevision);
        if ($to === $current->lifecycle || $sourceGeneration->value < $current->sourceGeneration->value || $privacyGeneration->value < $current->privacyGeneration->value) {
            $this->reject(ProblemCode::RevisionConflict);
        }
        $now = $this->now();
        $revision = $current->revision->next();
        $values = ['lifecycle' => $to->value, 'revision' => $revision->value, 'source_generation' => $sourceGeneration->value,
            'privacy_generation' => $privacyGeneration->value, 'updated_at' => $now, 'deleted_at' => $to === SurfaceLifecycle::Deleted ? $now : null];
        $changed = $this->query('surface_instances')->where('incarnation', $current->ref->incarnation->value)->where('revision', $expectedRevision->value)->update($values);
        if ($changed !== 1) {
            $this->reject(ProblemCode::RevisionConflict);
        }
        if ($to === SurfaceLifecycle::Deleted) {
            $this->owner->table('tombstones')->insert([...$this->identity(), 'incarnation' => $current->ref->incarnation->value,
                'surface_id' => $current->id, 'max_revision' => $revision->value, 'source_generation' => $sourceGeneration->value, 'deleted_at' => $now]);
        }

        return $this->lock();
    }

    /** Explicit trusted recreation only. New ref must be exactly next incarnation after the locked tombstone.
     * No replay entry uses this primitive. The previous tombstone is never changed or purged.
     */
    public function recreate(Revision $expectedDeletedRevision, Revision $sourceGeneration, Revision $privacyGeneration): SurfaceInstance
    {
        $current = $this->locked();
        if ($current->lifecycle !== SurfaceLifecycle::Deleted || $this->owner->handle()->surface->incarnation->value !== $current->ref->incarnation->next()->value) {
            $this->reject(ProblemCode::SurfaceIncarnationMismatch);
        }
        $this->expected(current: $current, expected: $expectedDeletedRevision);
        $tombstone = $this->query('tombstones')->where('incarnation', $current->ref->incarnation->value)->first();
        if ($tombstone === null || $this->integer($tombstone->max_revision) !== $current->revision->value
            || $this->integer($tombstone->source_generation) !== $current->sourceGeneration->value
            || $sourceGeneration->value <= $current->sourceGeneration->value) {
            $this->reject(ProblemCode::RevisionConflict);
        }
        $values = $this->initial(sourceGeneration: $sourceGeneration, privacyGeneration: $privacyGeneration);
        // Revision is per incarnation. New identity gets a new UUIDv7 and starts at revision 1.
        $changed = $this->query('surface_instances')->where('revision', $expectedDeletedRevision->value)->where('incarnation', $current->ref->incarnation->value)->update($values);
        if ($changed !== 1) {
            $this->reject(ProblemCode::RevisionConflict);
        }

        return $this->lock();
    }

    private function locked(): SurfaceInstance
    {
        $this->owner->requireTransaction();
        $row = $this->query('surface_instances')->lockForUpdate()->first();
        if ($row === null) {
            $this->reject(ProblemCode::SurfaceNotFound);
        }
        $handle = $this->owner->handle();
        $ref = $handle->surface;

        return new SurfaceInstance(id: $this->text($row->id), installation: $handle->actor->installation,
            ref: new SurfaceRef(panel: $ref->panel, kind: $ref->kind, host: $ref->host, incarnation: new Incarnation($this->integer($row->incarnation))),
            lifecycle: SurfaceLifecycle::from($this->text($row->lifecycle)), revision: new Revision($this->integer($row->revision)),
            sourceGeneration: new Revision($this->integer($row->source_generation)), privacyGeneration: new Revision($this->integer($row->privacy_generation)),
            createdAt: $this->date($this->text($row->created_at)), updatedAt: $this->date($this->text($row->updated_at)), deletedAt: $row->deleted_at === null ? null : $this->date($this->text($row->deleted_at)));
    }

    private function sameIncarnation(SurfaceInstance $current): void
    {
        if (! $current->ref->equals($this->owner->handle()->surface)) {
            $this->reject(ProblemCode::SurfaceIncarnationMismatch);
        }
    }

    private function active(SurfaceInstance $current): void
    {
        if ($current->lifecycle === SurfaceLifecycle::Deleted) {
            $this->reject(ProblemCode::ContentDeleted);
        }
    }

    private function expected(SurfaceInstance $current, Revision $expected): void
    {
        if (! $current->revision->equals($expected)) {
            $this->reject(ProblemCode::RevisionConflict);
        }
    }

    /** @return array{installation: string, panel: string, kind: string, host_type: string, host_key: string} */
    private function identity(): array
    {
        $handle = $this->owner->handle();
        $ref = $handle->surface;

        return ['installation' => $handle->actor->installation->id, 'panel' => $ref->panel->value, 'kind' => $ref->kind->value, 'host_type' => $ref->host->type, 'host_key' => $ref->host->value];
    }

    private function query(string $entity): Builder
    {
        return $this->owner->table($entity)->where($this->identity());
    }

    /** @return array<string, int|string|null> */
    private function initial(Revision $sourceGeneration, Revision $privacyGeneration): array
    {
        $now = $this->now();

        return [...$this->identity(), 'id' => Ids::uuid7(), 'incarnation' => $this->owner->handle()->surface->incarnation->value,
            'lifecycle' => SurfaceLifecycle::Live->value, 'revision' => 1, 'source_generation' => $sourceGeneration->value,
            'privacy_generation' => $privacyGeneration->value, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];
    }

    private function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new InvalidPayloadException('Invalid stored surface integer');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidPayloadException('Invalid stored surface text');
        }

        return $value;
    }

    private function reject(ProblemCode $code): never
    {
        throw new SurfaceStateException($code);
    }
}
