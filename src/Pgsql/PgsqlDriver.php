<?php

/**
 * Part of the Joomla Framework Database Package
 *
 * @copyright  Copyright (C) 2005 - 2021 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Database\Pgsql;

use Joomla\Database\Pdo\PdoDriver;

/**
 * PostgreSQL PDO Database Driver
 *
 * @since  1.5.0
 */
class PgsqlDriver extends PdoDriver
{
    /**
     * The database driver name
     *
     * @var    string
     * @since  1.5.0
     */
    public $name = 'pgsql';

    /**
     * The character(s) used to quote SQL statement names such as table names or field names,
     * etc. The child classes should define this as necessary.  If a single character string the
     * same character is used for both sides of the quoted name, else the first character will be
     * used for the opening quote and the second for the closing quote.
     *
     * @var    string
     * @since  1.5.0
     */
    protected $nameQuote = '"';

    /**
     * The null or zero representation of a timestamp for the database driver.  This should be
     * defined in child classes to hold the appropriate value for the engine.
     *
     * @var    string
     * @since  1.5.0
     */
    protected $nullDate = '1970-01-01 00:00:00';

    /**
     * The minimum supported database version.
     *
     * @var    string
     * @since  1.5.0
     */
    protected static $dbMinimum = '9.4.0';

    /**
     * Operator used for concatenation
     *
     * @var    string
     * @since  1.5.0
     */
    protected $concat_operator = '||';

    /**
     * Database object constructor
     *
     * @param   array  $options  List of options used to configure the connection
     *
     * @since   1.5.0
     */
    public function __construct($options)
    {
        $options['driver']   = 'pgsql';
        $options['host']     = $options['host'] ?? 'localhost';
        $options['user']     = $options['user'] ?? '';
        $options['password'] = $options['password'] ?? '';
        $options['database'] = $options['database'] ?? '';
        $options['port']     = $options['port'] ?? null;

        // Finalize initialization
        parent::__construct($options);
    }

    /**
     * Connects to the database if needed.
     *
     * @return  void
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function connect()
    {
        if ($this->getConnection()) {
            return;
        }

        parent::connect();

        $this->setQuery('SET standard_conforming_strings = off')->execute();
    }

    /**
     * Method to get the database collation in use by sampling a text field of a table in the database.
     *
     * @return  string|boolean  The collation in use by the database or boolean false if not supported.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getCollation()
    {
        // https://www.postgresql.org/docs/current/release-16.html
        if (version_compare($this->getVersion(), '16.0', '>=')) {
            $this->setQuery('SELECT datcollate AS lc_collate FROM pg_database WHERE datname = current_database()');
        } else {
            $this->setQuery('SHOW LC_COLLATE');
        }

        $array = $this->loadAssocList();

        return $array[0]['lc_collate'];
    }

    /**
     * Method to get the database connection collation in use by sampling a text field of a table in the database.
     *
     * @return  string|boolean  The collation in use by the database connection (string) or boolean false if not supported.
     *
     * @since   1.6.0
     * @throws  \RuntimeException
     */
    public function getConnectionCollation()
    {
        // https://www.postgresql.org/docs/current/release-16.html
        if (version_compare($this->getVersion(), '16.0', '>=')) {
            $this->setQuery('SELECT datcollate AS lc_collate FROM pg_database WHERE datname = current_database()');
        } else {
            $this->setQuery('SHOW LC_COLLATE');
        }

        $array = $this->loadAssocList();

        return $array[0]['lc_collate'];
    }

    /**
     * Method to get the database encryption details (cipher and protocol) in use.
     *
     * @return  string  The database encryption details.
     *
     * @since   2.0.0
     * @throws  \RuntimeException
     */
    public function getConnectionEncryption(): string
    {
        // Requires PostgreSQL 9.5 or newer
        if (version_compare($this->getVersion(), '9.5', '<')) {
            return '';
        }

        $query = $this->createQuery()
            ->select($this->quoteName(['version', 'cipher']))
            ->from($this->quoteName('pg_stat_ssl'))
            ->where($this->quoteName('pid') . ' = pg_backend_pid()');

        $variables = $this->setQuery($query)->loadAssoc();

        if (!empty($variables['cipher'])) {
            return $variables['version'] . ' (' . $variables['cipher'] . ')';
        }

        return '';
    }

    /**
     * Method to test if the database TLS connections encryption are supported.
     *
     * @return  boolean  Whether the database supports TLS connections encryption.
     *
     * @since   2.0.0
     */
    public function isConnectionEncryptionSupported(): bool
    {
        $variables = $this->setQuery('SHOW "ssl"')->loadAssoc();

        return !empty($variables['ssl']) && $variables['ssl'] === 'on';
    }

    /**
     * Internal function to get the name of the default schema for the current PostgreSQL connection.
     * That is the schema where tables are created by Joomla.
     *
     * @return  string
     *
     * @since   1.8.0
     */
    private function getDefaultSchema()
    {
        // Supported since PostgreSQL 7.3
        $this->setQuery('SELECT (current_schemas(false))[1]');

        return $this->loadResult();
    }

    /**
     * Shows the table CREATE statement that creates the given tables.
     *
     * This is unsupported by PostgreSQL.
     *
     * @param   mixed  $tables  A table name or a list of table names.
     *
     * @return  array  An empty array because this function is not supported by PostgreSQL.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getTableCreate($tables)
    {
        return [];
    }

    /**
     * Retrieves field information about a given table.
     *
     * @param   string   $table     The name of the database table.
     * @param   boolean  $typeOnly  True to only return field types.
     *
     * @return  array  An array of fields for the database table.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getTableColumns($table, $typeOnly = true)
    {
        $this->connect();

        $result        = [];
        $tableSub      = $this->replacePrefix($table);
        $defaultSchema = $this->getDefaultSchema();

        $this->setQuery(
            '
			SELECT a.attname AS "column_name",
				pg_catalog.format_type(a.atttypid, a.atttypmod) as "type",
				CASE WHEN a.attnotnull IS TRUE
					THEN \'NO\'
					ELSE \'YES\'
				END AS "null",
				CASE WHEN pg_catalog.pg_get_expr(adef.adbin, adef.adrelid, true) IS NOT NULL
					THEN pg_catalog.pg_get_expr(adef.adbin, adef.adrelid, true)
				END as "Default",
				CASE WHEN pg_catalog.col_description(a.attrelid, a.attnum) IS NULL
				THEN \'\'
				ELSE pg_catalog.col_description(a.attrelid, a.attnum)
				END  AS "comments"
			FROM pg_catalog.pg_attribute a
			LEFT JOIN pg_catalog.pg_attrdef adef ON a.attrelid=adef.adrelid AND a.attnum=adef.adnum
			LEFT JOIN pg_catalog.pg_type t ON a.atttypid=t.oid
			WHERE a.attrelid =
				(SELECT oid FROM pg_catalog.pg_class WHERE relname=' . $this->quote($tableSub) . '
					AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
					nspname = ' . $this->quote($defaultSchema) . ')
				)
			AND a.attnum > 0 AND NOT a.attisdropped
			ORDER BY a.attnum'
        );

        $fields = $this->loadObjectList();

        if ($typeOnly) {
            foreach ($fields as $field) {
                $result[$field->column_name] = preg_replace('/[(0-9)]/', '', $field->type);
            }
        } else {
            foreach ($fields as $field) {
                if ($field->Default !== null) {
                    // Normalise default values like datetime
                    if (preg_match('/^\'(.*)\'::.*/', $field->Default, $matches)) {
                        $field->Default = $matches[1];
                    }

                    // Change Postgresql's NULL::* type with PHP's null one. Do this last to avoid PHP type issues in PHP 8.1 and up
                    if (preg_match('/^NULL::*/', $field->Default)) {
                        $field->Default = null;
                    }
                }

                // Do some dirty translation to MySQL output.
                // @todo: Come up with and implement a standard across databases.
                $result[$field->column_name] = (object) [
                    'column_name' => $field->column_name,
                    'type'        => $field->type,
                    'null'        => $field->null,
                    'Default'     => $field->Default,
                    'comments'    => '',
                    'Field'       => $field->column_name,
                    'Type'        => $field->type,
                    'Null'        => $field->null,
                    // @todo: Improve query above to return primary key info as well
                    // 'Key' => ($field->PK == '1' ? 'PRI' : '')
                ];
            }
        }

        return $result;
    }

    /**
     * Get the details list of keys for a table.
     *
     * @param   string  $table  The name of the table.
     *
     * @return  array  An array of the column specification for the table.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getTableKeys($table)
    {
        $this->connect();

        // To check if table exists and prevent SQL injection
        $tableList = $this->getTableList();
        $tableSub  = $this->replacePrefix($table);

        if (\in_array($tableSub, $tableList, true)) {
            // Get the details columns information.
            $this->setQuery(
                '
				SELECT indexname AS "idxName", indisprimary AS "isPrimary", indisunique  AS "isUnique", indkey AS "indKey",
					CASE WHEN indisprimary = true THEN
						( SELECT \'ALTER TABLE \' || tablename || \' ADD \' || pg_catalog.pg_get_constraintdef(const.oid, true)
							FROM pg_constraint AS const WHERE const.conname= pgClassFirst.relname )
					ELSE pg_catalog.pg_get_indexdef(indexrelid, 0, true)
					END AS "Query"
				FROM pg_indexes
				LEFT JOIN pg_class AS pgClassFirst ON indexname=pgClassFirst.relname
				LEFT JOIN pg_index AS pgIndex ON pgClassFirst.oid=pgIndex.indexrelid
				WHERE tablename=' . $this->quote($tableSub) . ' ORDER BY indkey'
            );

            return $this->loadObjectList();
        }

        return [];
    }

    /**
     * Get the list of column names this index indexes.
     *
     * @param   string  $table   The name of the table.
     * @param   string  $indKey  The list of column numbers for the table
     *
     * @return  string  A list of the column names for the table.
     *
     * @since   2.0.0
     * @throws  \RuntimeException
     */
    public function getNamesKey($table, $indKey)
    {
        $this->connect();

        $tableSub = $this->replacePrefix($table);

        $tabInd   = explode(' ', $indKey);
        $colNames = [];

        foreach ($tabInd as $numCol) {
            $query = $this->createQuery()
                ->select('attname')
                ->from('pg_attribute')
                ->join('LEFT', 'pg_class ON pg_class.relname=' . $this->quote($tableSub))
                ->where('attnum=' . $numCol . ' AND attrelid=pg_class.oid');
            $this->setQuery($query);
            $colNames[] = $this->loadResult();
        }

        return implode(', ', $colNames);
    }

    /**
     * Method to get an array of all tables in the database.
     *
     * @return  array  An array of all the tables in the database.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getTableList()
    {
        $query = $this->createQuery()
            ->select('table_name')
            ->from('information_schema.tables')
            ->where('table_type = ' . $this->quote('BASE TABLE'))
            ->where('table_schema NOT IN (' . $this->quote('pg_catalog') . ', ' . $this->quote('information_schema') . ')')
            ->order('table_name ASC');

        $this->setQuery($query);

        return $this->loadColumn();
    }

    /**
     * Get the details list of sequences for a table.
     *
     * @param   string  $table  The name of the table.
     *
     * @return  array  An array of sequences specification for the table.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function getTableSequences($table)
    {
        // To check if table exists and prevent SQL injection
        $tableList = $this->getTableList();
        $tableSub  = $this->replacePrefix($table);

        if (\in_array($tableSub, $tableList, true)) {
            $name = [
                's.relname', 'n.nspname', 't.relname', 'a.attname', 'info.data_type',
                'info.minimum_value', 'info.maximum_value', 'info.increment', 'info.cycle_option', 'info.start_value',
            ];

            $as = [
                'sequence', 'schema', 'table', 'column', 'data_type', 'minimum_value', 'maximum_value', 'increment', 'cycle_option', 'start_value',
            ];

            // Get the details columns information.
            $query = $this->createQuery()
                ->select($this->quoteName($name, $as))
                ->from('pg_class AS s')
                ->leftJoin("pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass AND d.refclassid = 'pg_class'::regclass")
                ->leftJoin('pg_class t ON t.oid = d.refobjid')
                ->leftJoin('pg_namespace n ON n.oid = t.relnamespace')
                ->leftJoin('pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid')
                ->leftJoin('information_schema.sequences AS info ON info.sequence_name = s.relname')
                ->where('s.relkind = ' . $this->quote('S') . ' AND d.deptype = ' . $this->quote('a') . ' AND t.relname = ' . $this->quote($tableSub));
            $this->setQuery($query);

            return $this->loadObjectList();
        }

        return [];
    }

    /**
     * Method to get the last value of a sequence in the database.
     *
     * @param   string  $sequence  The name of the sequence.
     *
     * @return  integer  The last value of the sequence.
     *
     * @since   2.0.0
     * @throws  \RuntimeException
     */
    public function getSequenceLastValue($sequence)
    {
        $this->connect();

        $query = $this->createQuery()
            ->select($this->quoteName('last_value'))
            ->from($sequence);

        $this->setQuery($query);

        return $this->loadResult();
    }

    /**
     * Method to get the is_called attribute of a sequence.
     *
     * @param   string  $sequence  The name of the sequence.
     *
     * @return  boolean  The is_called attribute of the sequence.
     *
     * @since   2.0.0
     * @throws  \RuntimeException
     */
    public function getSequenceIsCalled($sequence)
    {
        $this->connect();

        $query = $this->createQuery()
            ->select($this->quoteName('is_called'))
            ->from($sequence);

        $this->setQuery($query);

        return $this->loadResult();
    }

    /**
     * Locks a table in the database.
     *
     * @param   string  $tableName  The name of the table to unlock.
     *
     * @return  $this
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function lockTable($tableName)
    {
        $this->transactionStart();
        $this->setQuery('LOCK TABLE ' . $this->quoteName($tableName) . ' IN ACCESS EXCLUSIVE MODE')->execute();

        return $this;
    }

    /**
     * Renames a table in the database.
     *
     * @param   string  $oldTable  The name of the table to be renamed
     * @param   string  $newTable  The new name for the table.
     * @param   string  $backup    Not used by PostgreSQL.
     * @param   string  $prefix    Not used by PostgreSQL.
     *
     * @return  $this
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function renameTable($oldTable, $newTable, $backup = null, $prefix = null)
    {
        $this->connect();

        $oldTable = $this->replacePrefix($oldTable);
        $newTable = $this->replacePrefix($newTable);

        // To check if table exists and prevent SQL injection
        $tableList = $this->getTableList();

        // Origin Table does not exist
        if (!\in_array($oldTable, $tableList, true)) {
            // Origin Table not found
            throw new \RuntimeException('Table not found in Postgresql database.');
        }

        // Rename indexes
        $subQuery = $this->createQuery()
            ->select('indexrelid')
            ->from('pg_index, pg_class')
            ->where('pg_class.relname = ' . $this->quote($oldTable))
            ->where('pg_class.oid = pg_index.indrelid');

        $this->setQuery(
            $this->createQuery()
                ->select('relname')
                ->from('pg_class')
                ->where('oid IN (' . (string) $subQuery . ')')
        );

        $oldIndexes = $this->loadColumn();

        foreach ($oldIndexes as $oldIndex) {
            $changedIdxName = str_replace($oldTable, $newTable, $oldIndex);
            $this->setQuery('ALTER INDEX ' . $this->escape($oldIndex) . ' RENAME TO ' . $this->escape($changedIdxName))->execute();
        }

        // Rename sequences
        $subQuery = $this->createQuery()
            ->select('oid')
            ->from('pg_namespace')
            ->where('nspname NOT LIKE ' . $this->quote('pg_%'))
            ->where('nspname != ' . $this->quote('information_schema'));

        $this->setQuery(
            $this->createQuery()
                ->select('relname')
                ->from('pg_class')
                ->where('relkind = ' . $this->quote('S'))
                ->where('relnamespace IN (' . (string) $subQuery . ')')
                ->where('relname LIKE ' . $this->quote("%$oldTable%"))
        );

        $oldSequences = $this->loadColumn();

        foreach ($oldSequences as $oldSequence) {
            $changedSequenceName = str_replace($oldTable, $newTable, $oldSequence);
            $this->setQuery('ALTER SEQUENCE ' . $this->escape($oldSequence) . ' RENAME TO ' . $this->escape($changedSequenceName))->execute();
        }

        // Rename table
        $this->setQuery('ALTER TABLE ' . $this->escape($oldTable) . ' RENAME TO ' . $this->escape($newTable))->execute();

        return $this;
    }

    /**
     * This function return a field value as a prepared string to be used in a SQL statement.
     *
     * @param   array   $columns     Array of table's column returned by ::getTableColumns.
     * @param   string  $fieldName   The table field's name.
     * @param   string  $fieldValue  The variable value to quote and return.
     *
     * @return  string  The quoted string.
     *
     * @since   1.5.0
     */
    public function sqlValue($columns, $fieldName, $fieldValue)
    {
        switch ($columns[$fieldName]) {
            case 'boolean':
                $val = 'NULL';

                if ($fieldValue === 't' || $fieldValue === true || $fieldValue === 1 || $fieldValue === '1') {
                    $val = 'TRUE';
                } elseif ($fieldValue === 'f' || $fieldValue === false || $fieldValue === 0 || $fieldValue === '0') {
                    $val = 'FALSE';
                }

                break;

            case 'bigint':
            case 'bigserial':
            case 'integer':
            case 'money':
            case 'numeric':
            case 'real':
            case 'smallint':
            case 'serial':
            case 'numeric,':
                $val = $fieldValue === '' ? 'NULL' : $fieldValue;

                break;

            case 'timestamp without time zone':
            case 'date':
                if (empty($fieldValue)) {
                    $fieldValue = $this->getNullDate();
                }

                $val = $this->quote($fieldValue);

                break;

            default:
                $val = $this->quote($fieldValue);

                break;
        }

        return $val;
    }

    /**
     * Method to commit a transaction.
     *
     * @param   boolean  $toSavepoint  If true, commit to the last savepoint.
     *
     * @return  void
     *
     * @since   1.0
     * @throws  \RuntimeException
     */
    public function transactionCommit($toSavepoint = false)
    {
        $this->connect();

        if (!$toSavepoint || $this->transactionDepth <= 1) {
            parent::transactionCommit($toSavepoint);
        } else {
            $this->transactionDepth--;
        }
    }

    /**
     * Method to roll back a transaction.
     *
     * @param   boolean  $toSavepoint  If true, rollback to the last savepoint.
     *
     * @return  void
     *
     * @since   1.0
     * @throws  \RuntimeException
     */
    public function transactionRollback($toSavepoint = false)
    {
        $this->connect();

        if (!$toSavepoint || $this->transactionDepth <= 1) {
            parent::transactionRollback($toSavepoint);
        } else {
            $savepoint = 'SP_' . ($this->transactionDepth - 1);
            $this->setQuery('ROLLBACK TO SAVEPOINT ' . $this->quoteName($savepoint));

            if ($this->execute()) {
                $this->transactionDepth--;
                $this->setQuery('RELEASE SAVEPOINT ' . $this->quoteName($savepoint))->execute();
            }
        }
    }

    /**
     * Method to initialize a transaction.
     *
     * @param   boolean  $asSavepoint  If true and a transaction is already active, a savepoint will be created.
     *
     * @return  void
     *
     * @since   1.0
     * @throws  \RuntimeException
     */
    public function transactionStart($asSavepoint = false)
    {
        $this->connect();

        if (!$asSavepoint || !$this->transactionDepth) {
            parent::transactionStart($asSavepoint);
        } else {
            $savepoint = 'SP_' . $this->transactionDepth;
            $this->setQuery('SAVEPOINT ' . $this->quoteName($savepoint));

            if ($this->execute()) {
                $this->transactionDepth++;
            }
        }
    }

    /**
     * Inserts a row into a table based on an object's properties.
     *
     * @param   string  $table   The name of the database table to insert into.
     * @param   object  $object  A reference to an object whose public properties match the table fields.
     * @param   string  $key     The name of the primary key. If provided the object property is updated.
     *
     * @return  boolean    True on success.
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function insertObject($table, &$object, $key = null)
    {
        $columns = $this->getTableColumns($table);

        $fields = [];
        $values = [];

        // Iterate over the object variables to build the query fields and values.
        foreach (get_object_vars($object) as $k => $v) {
            // Skip columns that don't exist in the table.
            if (!\array_key_exists($k, $columns)) {
                continue;
            }

            // Only process non-null scalars.
            if (\is_array($v) || \is_object($v) || $v === null) {
                continue;
            }

            // Ignore any internal fields or primary keys with value 0.
            if (($k[0] === '_') || ($k == $key && (($v === 0) || ($v === '0')))) {
                continue;
            }

            // Ignore null timestamp fields.
            if ($columns[$k] === 'timestamp without time zone' && empty($v)) {
                continue;
            }

            // Prepare and sanitize the fields and values for the database query.
            $fields[] = $this->quoteName($k);
            $values[] = $this->sqlValue($columns, $k, $v);
        }

        // Create the base insert statement.
        $query = $this->createQuery();

        $query->insert($this->quoteName($table))
            ->columns($fields)
            ->values(implode(',', $values));

        if ($key) {
            $query->returning($key);

            // Set the query and execute the insert.
            $object->$key = $this->setQuery($query)->loadResult();
        } else {
            // Set the query and execute the insert.
            $this->setQuery($query)->execute();
        }

        return true;
    }

    /**
     * Test to see if the PostgreSQL connector is available.
     *
     * @return  boolean  True on success, false otherwise.
     *
     * @since   1.5.0
     */
    public static function isSupported()
    {
        return class_exists('\\PDO') && \in_array('pgsql', \PDO::getAvailableDrivers(), true);
    }

    /**
     * Returns an array containing database's table list.
     *
     * @return  array  The database's table list.
     *
     * @since   1.5.0
     */
    public function showTables()
    {
        $query = $this->createQuery()
            ->select('table_name')
            ->from('information_schema.tables')
            ->where('table_type=' . $this->quote('BASE TABLE'))
            ->where('table_schema NOT IN (' . $this->quote('pg_catalog') . ', ' . $this->quote('information_schema') . ' )');

        $this->setQuery($query);

        return $this->loadColumn();
    }

    /**
     * Get the substring position inside a string
     *
     * @param   string  $substring  The string being sought
     * @param   string  $string     The string/column being searched
     *
     * @return  integer  The position of $substring in $string
     *
     * @since   1.5.0
     */
    public function getStringPositionSql($substring, $string)
    {
        $this->setQuery("SELECT POSITION($substring IN $string)");
        $position = $this->loadRow();

        return $position['position'];
    }

    /**
     * Generate a random value
     *
     * @return  float  The random generated number
     *
     * @since   1.5.0
     */
    public function getRandom()
    {
        $this->setQuery('SELECT RANDOM()');
        $random = $this->loadAssoc();

        return $random['random'];
    }

    /**
     * Get the query string to alter the database character set.
     *
     * @param   string  $dbName  The database name
     *
     * @return  string  The query that alter the database query string
     *
     * @since   1.5.0
     */
    public function getAlterDbCharacterSet($dbName)
    {
        return 'ALTER DATABASE ' . $this->quoteName($dbName) . ' SET CLIENT_ENCODING TO ' . $this->quote('UTF8');
    }

    /**
     * Get the query string to create new Database in correct PostgreSQL syntax.
     *
     * @param   object   $options  object coming from "initialise" function to pass user and database name to database driver.
     * @param   boolean  $utf      True if the database supports the UTF-8 character set, not used in PostgreSQL "CREATE DATABASE" query.
     *
     * @return  string  The query that creates database, owned by $options['user']
     *
     * @since   1.5.0
     */
    public function getCreateDbQuery($options, $utf)
    {
        $query = 'CREATE DATABASE ' . $this->quoteName($options->db_name) . ' OWNER ' . $this->quoteName($options->db_user);

        if ($utf) {
            $query .= ' ENCODING ' . $this->quote('UTF-8');
        }

        return $query;
    }

    /**
     * This function replaces a string identifier with the configured table prefix.
     *
     * @param   string  $sql     The SQL statement to prepare.
     * @param   string  $prefix  The table prefix.
     *
     * @return  string  The processed SQL statement.
     *
     * @since   1.5.0
     */
    public function replacePrefix($sql, $prefix = '#__')
    {
        $sql = trim($sql);

        if (strpos($sql, '\'')) {
            // Sequence name quoted with ' ' but need to be replaced
            if (strpos($sql, 'currval')) {
                $sql = explode('currval', $sql);

                for ($nIndex = 1, $nIndexMax = \count($sql); $nIndex < $nIndexMax; $nIndex += 2) {
                    $sql[$nIndex] = str_replace($prefix, $this->tablePrefix, $sql[$nIndex]);
                }

                $sql = implode('currval', $sql);
            }

            // Sequence name quoted with ' ' but need to be replaced
            if (strpos($sql, 'nextval')) {
                $sql = explode('nextval', $sql);

                for ($nIndex = 1, $nIndexMax = \count($sql); $nIndex < $nIndexMax; $nIndex += 2) {
                    $sql[$nIndex] = str_replace($prefix, $this->tablePrefix, $sql[$nIndex]);
                }

                $sql = implode('nextval', $sql);
            }

            // Sequence name quoted with ' ' but need to be replaced
            if (strpos($sql, 'setval')) {
                $sql = explode('setval', $sql);

                for ($nIndex = 1, $nIndexMax = \count($sql); $nIndex < $nIndexMax; $nIndex += 2) {
                    $sql[$nIndex] = str_replace($prefix, $this->tablePrefix, $sql[$nIndex]);
                }

                $sql = implode('setval', $sql);
            }

            $explodedQuery = explode('\'', $sql);

            for ($nIndex = 0, $nIndexMax = \count($explodedQuery); $nIndex < $nIndexMax; $nIndex += 2) {
                if (strpos($explodedQuery[$nIndex], $prefix)) {
                    $explodedQuery[$nIndex] = str_replace($prefix, $this->tablePrefix, $explodedQuery[$nIndex]);
                }
            }

            $replacedQuery = implode('\'', $explodedQuery);
        } else {
            $replacedQuery = str_replace($prefix, $this->tablePrefix, $sql);
        }

        return $replacedQuery;
    }

    /**
     * Unlocks tables in the database, this command does not exist in PostgreSQL, it is automatically done on commit or rollback.
     *
     * @return  $this
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function unlockTables()
    {
        $this->transactionCommit();

        return $this;
    }

    /**
     * Updates a row in a table based on an object's properties.
     *
     * @param   string        $table   The name of the database table to update.
     * @param   object        $object  A reference to an object whose public properties match the table fields.
     * @param   array|string  $key     The name of the primary key.
     * @param   boolean       $nulls   True to update null fields or false to ignore them.
     *
     * @return  boolean
     *
     * @since   1.5.0
     * @throws  \RuntimeException
     */
    public function updateObject($table, &$object, $key, $nulls = false)
    {
        $columns = $this->getTableColumns($table);
        $fields  = [];
        $where   = [];

        if (\is_string($key)) {
            $key = [$key];
        }

        if (\is_object($key)) {
            $key = (array) $key;
        }

        // Create the base update statement.
        $statement = 'UPDATE ' . $this->quoteName($table) . ' SET %s WHERE %s';

        // Iterate over the object variables to build the query fields/value pairs.
        foreach (get_object_vars($object) as $k => $v) {
            // Skip columns that don't exist in the table.
            if (!\array_key_exists($k, $columns)) {
                continue;
            }

            // Only process scalars that are not internal fields.
            if (\is_array($v) || \is_object($v) || $k[0] === '_') {
                continue;
            }

            // Set the primary key to the WHERE clause instead of a field to update.
            if (\in_array($k, $key, true)) {
                $key_val = $this->sqlValue($columns, $k, $v);
                $where[] = $this->quoteName($k) . '=' . $key_val;

                continue;
            }

            // Prepare and sanitize the fields and values for the database query.
            if ($v === null) {
                // If the value is null and we do not want to update nulls then ignore this field.
                if (!$nulls) {
                    continue;
                }

                // If the value is null and we want to update nulls then set it.
                $val = 'NULL';
            } else {
                // The field is not null so we prep it for update.
                $val = $this->sqlValue($columns, $k, $v);
            }

            // Add the field to be updated.
            $fields[] = $this->quoteName($k) . '=' . $val;
        }

        // We don't have any fields to update.
        if (empty($fields)) {
            return true;
        }

        // Set the query and execute the update.
        $this->setQuery(sprintf($statement, implode(',', $fields), implode(' AND ', $where)));

        return $this->execute();
    }

    /**
     * Quotes a binary string to database requirements for use in database queries.
     *
     * @param   string  $data  A binary string to quote.
     *
     * @return  string  The binary quoted input string.
     *
     * @since   1.7.0
     */
    public function quoteBinary($data)
    {
        return "decode('" . bin2hex($data) . "', 'hex')";
    }

    /**
     * Replace special placeholder representing binary field with the original string.
     *
     * @param   string|resource  $data  Encoded string or resource.
     *
     * @return  string  The original string.
     *
     * @since   1.7.0
     */
    public function decodeBinary($data)
    {
        if (\is_resource($data)) {
            return stream_get_contents($data);
        }

        return $data;
    }
}
