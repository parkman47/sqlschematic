# Schema repair

Schema Repair is separate from normal migration execution. It reconciles migration
files with recorded history; it does not provide arbitrary history editing.

## Problems it diagnoses

- An applied migration record whose file is missing from deployment.
- An applied migration file whose checksum changed after execution.
- Pending migrations frozen behind either condition.
- A pending file byte-for-byte identical to a missing applied file.

Open the gated `schema-repair.php` page for diagnostics. Restoring the correct
original file resolves a missing-file or checksum-drift condition on the next check
without rewriting history.

## The only automated history repair

SqlSchematic can adopt a renamed migration only when every safety condition holds:

1. The old migration is recorded as applied and its file is missing.
2. The new migration exists and is pending.
3. Old and new SHA-256 checksums are exactly identical.
4. The new file is the only matching candidate.
5. No pending migration sorts before the proposed new ID.
6. The administrator explicitly confirms both IDs.

The operation changes only the bookkeeping ID and filename, executes no SQL, uses a
database transaction and advisory lock, and writes a repair audit record. Type the
exact confirmation phrase shown and click **Adopt identical rename**.

## Operations intentionally unavailable

Schema Repair cannot:

- accept changed SQL;
- overwrite an applied checksum;
- delete an applied migration record;
- mark a pending migration applied without exact historical evidence;
- rerun applied SQL;
- resolve ambiguous checksum matches; or
- adopt a rename that contradicts pending-file order.

For those cases, restore repository history or write a new corrective migration.

## Generic ordering example

Suppose history contains applied `015_normalize_status`, but deployment contains
pending `015_add_widget_priority` and an identical renamed `016_normalize_status`.
Automatic adoption is refused because a pending migration sorts before the proposed
adopted ID. Restore `015_normalize_status.sql`, remove its renamed duplicate, and
renumber the new change to `016_add_widget_priority.sql` before running migrations.
