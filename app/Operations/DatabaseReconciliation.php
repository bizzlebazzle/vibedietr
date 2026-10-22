<?php

namespace App\Operations;

use Illuminate\Database\Connection;
use RuntimeException;

/** Read-only evidence for a frozen MySQL database, never an authorization to release it. */
final class DatabaseReconciliation
{
    /** @return array<string, mixed> */
    public function capture(Connection $db): array
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new RuntimeException('recovery_mysql_required');
        }
        if ((int) $db->selectOne('SELECT @@foreign_key_checks AS enabled')->enabled !== 1) {
            throw new RuntimeException('recovery_constraints_disabled');
        }

        $columnGroups = [];
        foreach ($db->select('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA AS extra, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, COLUMN_NAME') as $column) {
            $name = $column->table_name;
            unset($column->table_name);
            $columnGroups[$name][$column->name] = (array) $column;
        }
        $indexGroups = [];
        foreach ($db->select('SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $index) {
            $name = $index->table_name;
            unset($index->table_name);
            $indexGroups[$name][] = $index;
        }
        $tables = [];
        foreach ($db->select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME', ['BASE TABLE']) as $table) {
            if ($table->engine !== 'InnoDB') {
                throw new RuntimeException('recovery_nontransactional_table');
            }
            $columns = $columnGroups[$table->name];
            $tables[$table->name] = [
                'columns' => $columns,
                'data' => $this->digest($db, $table->name, array_keys($columns)),
                'indexes' => $indexGroups[$table->name] ?? [],
            ];
        }
        $foreignKeys = $db->select('SELECT k.CONSTRAINT_NAME AS name, k.TABLE_NAME AS child, k.COLUMN_NAME AS child_column, k.REFERENCED_TABLE_NAME AS parent, k.REFERENCED_COLUMN_NAME AS parent_column, k.ORDINAL_POSITION AS position, r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION');
        $groups = [];
        foreach ($foreignKeys as $key) {
            $groups[$key->child.'.'.$key->name][] = $key;
        }
        foreach ($groups as $keys) {
            $conditions = [];
            $nonNull = [];
            foreach ($keys as $key) {
                $conditions[] = 'c.'.$this->quote($key->child_column).' = p.'.$this->quote($key->parent_column);
                $nonNull[] = 'c.'.$this->quote($key->child_column).' IS NOT NULL';
            }
            $sql = 'SELECT COUNT(*) AS n FROM '.$this->quote($keys[0]->child).' c WHERE '.implode(' AND ', $nonNull).' AND NOT EXISTS (SELECT 1 FROM '.$this->quote($keys[0]->parent).' p WHERE '.implode(' AND ', $conditions).')';
            if ((int) $db->selectOne($sql)->n !== 0) {
                throw new RuntimeException('recovery_orphaned_reference');
            }
        }

        $migrations = $db->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $files = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $files[pathinfo($file, PATHINFO_FILENAME)] = hash_file('sha256', $file);
        }
        foreach ($migrations as $migration) {
            if (! isset($files[$migration])) {
                throw new RuntimeException('recovery_unknown_migration');
            }
        }

        return ['format' => 1, 'tables' => $tables, 'foreign_keys' => $foreignKeys, 'applied' => $migrations, 'migration_files' => $files];
    }

    /** @param array<string, mixed> $baseline */
    public function verify(Connection $db, array $baseline, bool $additive = false): void
    {
        $current = json_decode($this->json($this->capture($db)), true, flags: JSON_THROW_ON_ERROR);
        $baseline = json_decode($this->json($baseline), true, flags: JSON_THROW_ON_ERROR);
        if (($baseline['format'] ?? null) !== 1 || ! isset($baseline['tables'], $baseline['applied'], $baseline['migration_files'], $baseline['foreign_keys'])) {
            throw new RuntimeException('recovery_invalid_manifest');
        }
        foreach ($baseline['applied'] as $migration) {
            if (! in_array($migration, $current['applied'], true) || ($baseline['migration_files'][$migration] ?? null) !== ($current['migration_files'][$migration] ?? null)) {
                throw new RuntimeException('recovery_migration_history_changed');
            }
        }
        if (! $additive && $this->json($baseline) !== $this->json($current)) {
            throw new RuntimeException('recovery_reconciliation_mismatch');
        }
        if ($additive) {
            if ($baseline['migration_files'] !== $current['migration_files']) {
                throw new RuntimeException('recovery_release_changed');
            }
            foreach ($baseline['tables'] as $name => $table) {
                foreach ($table['columns'] as $column => $definition) {
                    if (($current['tables'][$name]['columns'][$column] ?? null) !== $definition) {
                        throw new RuntimeException('recovery_existing_column_changed');
                    }
                }
                foreach ($table['indexes'] as $index) {
                    if (! in_array($index, json_decode($this->json($current['tables'][$name]['indexes']), true, flags: JSON_THROW_ON_ERROR), true)) {
                        throw new RuntimeException('recovery_existing_index_changed');
                    }
                }
                if ($name !== 'migrations' && $this->digest($db, $name, array_keys($table['columns'])) !== $table['data']) {
                    throw new RuntimeException('recovery_existing_data_changed');
                }
            }
            foreach ($baseline['foreign_keys'] as $key) {
                if (! in_array($key, json_decode($this->json($current['foreign_keys']), true, flags: JSON_THROW_ON_ERROR), true)) {
                    throw new RuntimeException('recovery_existing_constraint_changed');
                }
            }
            if (array_diff(array_keys($current['migration_files']), $current['applied']) !== []) {
                throw new RuntimeException('recovery_pending_migrations');
            }
        }
    }

    /** @param list<string> $columns
     * @return array{count: int, sha256: string}
     */
    private function digest(Connection $db, string $table, array $columns): array
    {
        // Sort row digests, not private row contents. Order-independent and duplicate-sensitive.
        $hashes = [];
        foreach ($db->table($table)->select($columns)->cursor() as $row) {
            $values = array_map(fn (mixed $value): ?string => $value === null ? null : base64_encode((string) $value), (array) $row);
            $hashes[] = hash('sha256', $this->json($values));
        }
        sort($hashes, SORT_STRING);

        return ['count' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
    }

    private function quote(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
