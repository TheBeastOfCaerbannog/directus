<?php

namespace Directus\Db\TableGateway;

use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Where;

class RelationalTableGateway extends AclAwareTableGateway {

    protected $many_to_one_uis = array('many_to_one', 'single_media');

    /**
     * These columns types are aliases for "associations". They don't have real, corresponding columns in the DB.
     */
    protected $association_types = array('ONETOMANY','MANYTOMANY','ALIAS');

    public function addOrUpdateForeignRecords($schema, $parentRow, $parentActivityLogId) {
        // This will need to have occurred above, and the $parentActivityLogId is what we get:
        // Log it
        // $action = isset($data['id']) ? 'UPDATE' : 'ADD';
        // $master_item = find($schema,'master',true);
        // $identifier = isset($master_item) ? $data[$master_item['column_name']] : null;
        // $activity_id = $this->log_activity('ENTRY',$tbl_name, $action, $id, $identifier, $data, $parent_activity_id);

        // Create foreign row and update local column with the data id
        foreach($schema as $column) {
            $colName = $column['id']; // correct var naming?

            // Ignore non-arrays & empty arrays
            if(!(isset($parentRow[$colName]) && is_array($parentRow[$colName]) && !empty($parentRow[$colName])))
                continue;

            $foreignDataSet = $parentRow[$colName];
            $colUiType = $column['ui'];

            /** Many-to-One */
            if (in_array($colUiType, $this->many_to_one_uis))
                $foreign_id = $this->addOrUpdateManyToOne($foreignDataSet);

            /** One-to-Many, Many-to-Many */
            elseif (in_array($column['type'], $this->association_types)) {
                $foreignTableName = $column['table_related'];
                $foreignJoinColumn = $column['junction_key_right'];
                switch (strtolower($column['type'])) {

                    /** One-to-Many */
                    case 'onetomany':
                        foreach ($foreignDataSet as $foreignRecord) {
                            $foreignRecord[$foreignJoinColumn] = $parentRow['id'];
                            $row = $this->addOrUpdateRecordByArray($foreignTableName, $foreignRecord);
                        }
                        break;

                    /** Many-to-Many */
                    case 'manytomany':
                        $junctionTableName = $item['junction_table'];
                        $junctionKeyLeft = $item['junction_key_left'];
                        foreach($data as $junctionRow) {

                            /** This association is designated for removal */
                            if (isset($junctionRow['active']) && $junctionRow['active'] == 0) {
                                $JunctionTable = new RelationalTableGateway($this->aclProvider, $junctionTableName, $this->adapter);
                                $Where = new Where;
                                $Where->equalTo('id', $junctionRow['id']);
                                $JunctionTable->delete($Where);
                                /** @todo what's up the log */
                                // $this->log_activity($junctionTableName, 'DELETE', $junctionRow['id'], 'TEST', null, $activity_id);
                                continue;
                            }

                            // Update foreign table
                            $foreign_id = $this->set_entry_relational($foreignTableName, $junctionRow['data'], $activity_id);

                            $junction_table_data = array(
                                $junctionKeyLeft   => $id,
                                $foreignJoinColumn => $foreign_id
                            );

                            if (isset($junctionRow['id']))
                                $junction_table_data['id'] = $junctionRow['id'];

                            $this->set_entry_relational($junction_table, $junction_table_data, $activity_id);
                        }
                        break;
                }

            }

            $data[$colName] = $foreign_id;

        }
    }

    protected function addOrUpdateRecordByArray($tableName, array $recordData) {
        $rowExists = isset($recordData['id']);
        $row = $this->newRow($tableName);
        $row->populate($recordData, $rowExists);
        $row->save();
        return $row;
    }

    protected function addOrUpdateOneToMany($ownerId, $foreignTableName, $foreignJoinColumn, $foreignRecords) {
    }

    protected function addOrUpdateManyToOne(array $foreignRecord) {
        // Update/Add foreign record
        $rowExists = isset($foreignRecord['id']);
        if($rowExists)
            unset($foreignRecord['date_uploaded']);
        $newRow = $this->addOrUpdateRecordByArray('directus_media', $foreignRecord);
        /** Register in activity log */
        $row = $row->toArray();
        $activityType = $rowExists ? 'UPDATE' : 'ADD';
        $this->log_activity('MEDIA', 'directus_media', $activityType, $row['id'], $row['title'], $row, $parentRow['id']);
        return $row['id'];
        /* IS THIS conditional necessary still?
        if ('single_media' == $colUiType) {
            // ...
            // What's just above would be here.
        }
        else
            // Fix this. should probably not relate to directus_media, but the specified "related_table"
            $foreign_id = $this->set_entry('directus_media', $foreignRecord);
        */
    }

    protected function addOrUpdateManyToMany($junctionTableName, $junctionKeyLeft, $foreignJoinColumn) {
        $junctionTableName = $item['junction_table'];
        $junctionKeyLeft = $item['junction_key_left'];
        foreach($data as $junctionRow) {

            // Delete?
            if (isset($junctionRow['active']) && ($junctionRow['active'] == '0')) {
                $junctionRow['id'] = intval($junctionRow['id']);
                $this->dbh->exec("DELETE FROM $junctionTableName WHERE id = " . $junctionRow['id']);
                $this->log_activity($junctionTableName, 'DELETE', $junctionRow['id'], 'TEST', null, $activity_id);
                continue;
            }

            // Update foreign table
            $foreign_id = $this->set_entry_relational($foreignTableName, $junctionRow['data'], $activity_id);

            $junction_table_data = array(
                $junctionKeyLeft   => $id,
                $foreignJoinColumn => $foreign_id
            );

            if (isset($junctionRow['id']))
                $junction_table_data['id'] = $junctionRow['id'];

            $this->set_entry_relational($junction_table, $junction_table_data, $activity_id);
        }
    }

    /**
     * This function does X things:
     *
     * 1. Updates immediate row data
     * 2. Manages many-to-one relationships:
     *    a. Establishes these for the parent row.
     *    b. Manages the foreign record.
     */
    function set_entry_relational($tbl_name, $data, $parent_activity_id=null) {
        $log = Bootstrap::get('app')->getLog();
        $olddb = Bootstrap::get('olddb');

        // These columns are aliases and doesn't have corresponding
        // columns in the DB, for example 'alias' and 'relational'
        $alias_types = array('ONETOMANY','MANYTOMANY','ALIAS');
        $alias_columns = array();
        $alias_meta = array();
        $alias_data = array();

        // Grab the schema so we can see what's possible
        $schema = $olddb->get_table($tbl_name);

        $this->logger(__CLASS__ . "#" . __FUNCTION__ . " with args:");
        $this->logger(print_r(func_get_args(), true));
        $this->logger("Produces schema val:");
        $this->logger(print_r($schema, true));

        // Create foreign row and update local column with the data id
        foreach($schema as $column) {
            if (in_array($column['ui'], $this->many_to_one_uis) && is_array($data[$column['id']])) {
                $foreign_data = $data[$column['id']];

                // Threre is no nested item. Go ahead...
                if (sizeof($foreign_data) == 0) {
                    $data[$column['id']] = null;
                    continue;
                }

                // Update/Add foreign data
                if ($column['ui'] == 'single_media') {
                    $foreign_id = $this->set_media($foreign_data);
                    // {does}
                    // $isExisting = isset($data['id']);
                    // if ($isExisting)
                    //     unset($data['date_uploaded']);
                    // $id = $this->set_entry('directus_media', $data);
                    // if(!isset($data['title']))
                    //     $data['title'] = '';
                    // $this->log_activity('MEDIA', 'directus_media', $isExisting ? 'UPDATE' : 'ADD', $id, $data['title'], $data, $parent_id);
                    // return $id;
                } else {
                    //Fix this. should probably not relate to directus_media, but the specified "related_table"
                    $foreign_id = $this->set_entry('directus_media', $foreign_data);
                    // $record = $this->newRow('directus_media');
                    /**
                     * @refactor steps, whether set or single
                     * 1. For those records which have IDs, do these IDs already exist in the DB?
                     * 2. Run update queries on those which do
                     * 3. Run insert queries on those which don't
                     * * But isn't this just one record we're dealing with?
                     */
                }

                // Save the data id...
                $data[$column['id']] = $foreign_id;
            }
        }

        // Seperate relational columns from schema
        foreach($schema as $column) {
            if (in_array($column['type'], $alias_types)) {
                array_push($alias_columns, $column['column_name']);
                $alias_meta[$column['column_name']] = $column;
            }
        }

        // Seperate relational data
        foreach($data as $column_name => $value) {
            if (in_array($column_name, $alias_columns)) {
                $alias_data[$column_name] = $value;
                unset($data[$column_name]);
            }
        }

        // Update local (non-relational) data
        $id = $this->set_entry($tbl_name, $data);
        /**
         * @refactor  make update query via QB
         */

        // Log it
        $action = isset($data['id']) ? 'UPDATE' : 'ADD';
        $master_item = find($schema,'master',true);
        $identifier = isset($master_item) ? $data[$master_item['column_name']] : null;
        $activity_id = $this->log_activity('ENTRY',$tbl_name, $action, $id, $identifier, $data, $parent_activity_id);

        // Update the related columns
        foreach($alias_meta as $column_name => $item) {

            if (!isset($alias_data[$column_name])) continue;

            $data = $alias_data[$column_name];
            $table_related = $item['table_related'];
            $junction_key_right = $item['junction_key_right'];

            switch($item['type']) {
                case 'ONETOMANY':
                    foreach ($data as $foreign_table_row) {
                        $foreign_table_row[$junction_key_right] = $id;
                        $this->set_entry_relational($table_related, $foreign_table_row, $activity_id);
                    }
                    break;

                case 'MANYTOMANY':
                    $junction_table = $item['junction_table'];
                    $junction_key_left = $item['junction_key_left'];
                    foreach($data as $junction_table_row) {

                        // Delete?
                        if (isset($junction_table_row['active']) && ($junction_table_row['active'] == '0')) {
                            $junction_table_id = intval($junction_table_row['id']);
                            $this->dbh->exec("DELETE FROM $junction_table WHERE id=$junction_table_id");
                            $this->log_activity($junction_table, 'DELETE', $junction_table_id, 'TEST', null, $activity_id);
                            continue;
                        }

                        // Update foreign table
                        $foreign_id = $this->set_entry_relational($table_related, $junction_table_row['data'], $activity_id);

                        $junction_table_data = array(
                            $junction_key_left => $id,
                            $junction_key_right => $foreign_id
                        );

                        if (isset($junction_table_row['id']))
                            $junction_table_data['id'] = $junction_table_row['id'];

                        $this->set_entry_relational($junction_table, $junction_table_data, $activity_id);
                    }
                    break;
            }
        }
        return $id;
    }

    public function getEntries($params = array()) {
        // tmp transitional.
        global $db;

        $logger = $this->logger();

        $defaultParams = array(
            'order_column' => 'id', // @todo validate $params['order_*']
            'order_direction' => 'DESC',
            'fields' => '*',
            'per_page' => 500,
            'skip' => 0,
            'id' => -1,
            'search' => null,
            'active' => null
        );

        if(isset($params['per_page']) && isset($params['current_page']))
            $params['skip'] = $params['current_page'] * $params['per_page'];

        if(isset($params['fields']) && is_array($params['fields']))
            $params['fields'] = array_merge(array('id'), $params['fields']);

        $params = array_merge($defaultParams, $params);

        $platform = $this->adapter->platform; // use for quoting

        // Get table column schema
        $table_schema = $db->get_table($this->table);

        // Separate alias fields from table schema array
        $alias_fields = $this->filterSchemaAliasFields($table_schema); // (fmrly $alias_schema)

        $sql = new Sql($this->adapter);
        $select = $sql->select()->from($this->table);
        // And so on
        $select->group('id')
            ->order(implode(' ', array($params['order_column'], $params['order_direction'])))
            ->limit($params['per_page'])
            ->offset($params['skip']);

        // @todo incorporate search

        // Table has `active` column?
        $has_active_column = $this->schemaHasActiveColumn($table_schema);

        // Note: be sure to explicitly check for null, because the value may be
        // '0' or 0, which is meaningful.
        if (null !== $params['active'] && $has_active_column) {
            $haystack = is_array($params['active'])
                ? $params['active']
                : explode(",", $params['active']);
            $select->where->in('active', $haystack);
        }

        // Where
        $select->where
            ->expression('-1 = ?', $params['id'])
            ->or
            ->equalTo('id', $params['id']);

        // $logger->info($this->dumpSql($select));

        $results = $this->selectWith($select);

        // $this->__runDemoTestSuite($results);

        // Note: ensure this is sufficient, in lieu of incrementing within
        // the foreach loop below.
        $foundRows = count($results);

        // @todo make comment explaining this loop
        $table_entries = array();
        foreach ($results as $row) {
            $item = array();
            foreach ($table_schema as $col) {
                // Run custom data casting.
                $name = $col['column_name'];
                if($row->offsetExists($name))
                    $item[$name] = $this->parseMysqlType($row[$name], $col['type']);
            }
            $table_entries[] = $item;
        }

        // Eager-load related ManyToOne records
        $table_entries = $this->loadManyToOneData($table_schema, $table_entries);

        /**
         * Fetching a set of data
         */

        if (-1 == $params['id']) {
            $countActive = $this->count_active($this->table, !$has_active_column);

            $set = array_merge($countActive, array(
                'total'=> $foundRows,
                'rows'=> $table_entries
            ));
            return $set;
        }

        /**
         * Fetching one item
         */

        // @todo return null and let controller throw HTTP response
        if (0 == count($table_entries))
            throw new \DirectusException('Item not found!', 404);

        list($table_entry) = $table_entries;

        foreach ($alias_fields as $alias) {
            switch($alias['type']) {
                case 'MANYTOMANY':
                    $foreign_data = $this->loadManyToManyData($this->table, $alias['table_related'],
                        $alias['junction_table'], $alias['junction_key_left'], $alias['junction_key_right'],
                        $params['id']);
                    break;
                case 'ONETOMANY':
                    $foreign_data = $this->loadOneToManyData($alias['table_related'], $alias['junction_key_right'], $params['id']);
                    break;
            }
            if(isset($foreign_data)) {
                $column = $alias['column_name'];
                $table_entry[$column] = $foreign_data;
            }
        }

        return $table_entry;
    }

    /**
     *
     * ASSOCIATION FUNCTIONS
     *
     **/

    /**
     * Fetch related, foreign rows for one record's OneToMany relationships.
     *
     * @param string $table
     * @param string $column_name
     * @param string $column_equals
     */
    private function loadOneToManyData($table, $column_name, $column_equals) {
        // Run query
        $select = new Select($table);
        $select->where->equalTo($column_name, $column_equals);
        $TableGateway = new RelationalTableGateway($this->aclProvider, $table, $this->adapter);
        $rowset = $TableGateway->selectWith($select);
        $results = $rowset->toArray();
        // Process results
        foreach ($results as $row)
            array_walk($row, array($this, 'castFloatIfNumeric'));
        return array('rows' => $results);
    }

    /**
     * Fetch related, foreign rows for a whole rowset's ManyToOne relationships.
     * (Given a table's schema and rows, iterate and replace all of its foreign
     * keys with the contents of these foreign rows.)
     * @param  array $schema  Table schema array
     * @param  array $entries Table rows
     * @return array          Revised table rows, now including foreign rows
     */
    private function loadManyToOneData($table_schema, $table_entries) {
        // Identify the ManyToOne columns
        foreach ($table_schema as $col) {
            $isManyToOneColumn = in_array($col['ui'], $this->many_to_one_uis);
            if ($isManyToOneColumn) {
                $foreign_id_column = $col['id'];
                $foreign_table_name = ($col['ui'] == 'single_media') ? 'directus_media' : $col['options']['related_table'];

                // Aggregate all foreign keys for this relationship (for each row, yield the specified foreign id)
                $yield = function($row) use ($foreign_id_column) {
                    if(array_key_exists($foreign_id_column, $row))
                        return $row[$foreign_id_column];
                };
                $ids = array_map($yield, $table_entries);
                if (empty($ids))
                    continue;

                // Fetch the foreign data
                $select = new Select($foreign_table_name);
                $select->where->in('id', $ids);
                $TableGateway = new RelationalTableGateway($this->aclProvider, $foreign_table_name, $this->adapter);
                $rowset = $TableGateway->selectWith($select);
                $results = $rowset->toArray();

                $foreign_table = array();
                foreach ($results as $row) {
                    // @todo I wonder if all of this looping and casting is necessary
                    array_walk($row, array($this, 'castFloatIfNumeric'));
                    $foreign_table[$row['id']] = $row;
                }

                // Replace foreign keys with foreign rows
                foreach ($table_entries as &$parentRow) {
                    if(array_key_exists($foreign_id_column, $parentRow)) {
                        $foreign_id = $parentRow[$foreign_id_column];
                        $parentRow[$foreign_id_column] = null;
                        // "Did we retrieve the foreign row with this foreign ID in our recent query of the foreign table"?
                        if(array_key_exists($foreign_id, $foreign_table))
                            $parentRow[$foreign_id_column] = $foreign_table[$foreign_id];
                    }
                }
            }
        }
        return $table_entries;
    }

    /**
     * Fetch related, foreign rows for one record's ManyToMany relationships.
     * @param  string $table_name
     * @param  string $foreign_table
     * @param  string $junction_table
     * @param  string $junction_key_left
     * @param  string $junction_key_right
     * @param  string $column_equals
     * @return array                      Foreign rowset
     */
    private function loadManyToManyData($table_name, $foreign_table, $junction_table, $junction_key_left, $junction_key_right, $column_equals) {
        $foreign_join_column = "$junction_table.$junction_key_right";
        $join_column = "$junction_table.$junction_key_left";

        $sql = new Sql($this->adapter);
        $select = $sql->select()
            ->from($junction_table)
            ->join($foreign_table, "$foreign_join_column = $join_column")
            ->where(array($join_column => $column_equals));

        $JunctionTable = new RelationalTableGateway($this->aclProvider, $junction_table, $this->adapter);
        $results = $JunctionTable->selectWith($select);
        $results = $results->toArray();

        $foreign_data = array();
        foreach($results as $row) {
            array_walk($row, array($this, 'castFloatIfNumeric'));
            $foreign_data[] = array('id' => $row['id'], 'data' => $row);
        }
        return array('rows' => $foreign_data);
    }

    /**
     *
     * HELPER FUNCTIONS
     *
     **/

    /**
     * Remove Directus-managed virtual/alias fields from the table schema array
     * and return them as a separate array.
     * @param  array $schema Table schema array.
     * @return array         Alias fields
     */
    private function filterSchemaAliasFields(&$schema) {
        $alias_fields = array();
        foreach($schema as $i => $col) {
            // Is it a "virtual"/alias column?
            if(in_array($col['type'], array('ALIAS','ONETOMANY','MANYTOMANY'))) {
                // Remove them from the standard schema
                unset($schema[$i]);
                $alias_fields[] = $col;
            }
        }
        return $alias_fields;
    }

    /**
     * Does a table schema array contain an `active` column?
     * @param  array $schema Table schema array.
     * @return boolean
     */
    private function schemaHasActiveColumn($schema) {
        foreach($schema as $col) {
            if('active' == $col['column_name'])
                return true;
        }
        return false;
    }

    /**
     * Cast a php string to the same type as MySQL
     * @param  string $mysql_data MySQL result data
     * @param  string $mysql_type MySQL field type
     * @return mixed              Value cast to PHP type
     */
    private function parseMysqlType($mysql_data, $mysql_type = null) {
        $mysql_type = strtolower($mysql_type);
        switch ($mysql_type) {
            case null:
                break;
            case 'blob':
            case 'mediumblob':
                return base64_encode($mysql_data);
            case 'year':
            case 'int':
            case 'long':
            case 'tinyint':
                return (int) $mysql_data;
            case 'float':
                return (float) $mysql_data;
            case 'date':
            case 'datetime':
                return date("r", strtotime($mysql_data));
            case 'var_string':
                return $mysql_data;
        }
        // If type is null & value is numeric, cast to integer.
        if (is_numeric($mysql_data))
            return (float) $mysql_data;
        return $mysql_data;
    }

    /**
     * @refactor
     */
    function count_active($tbl_name, $no_active=false) {
        $result = array('active'=>0);
        if ($no_active) {
            $sql = "SELECT COUNT(*) as count, 'active' as active FROM $tbl_name";
        } else {
            $sql = "SELECT
                CASE active
                    WHEN 0 THEN 'trash'
                    WHEN 1 THEN 'active'
                    WHEN 2 THEN 'inactive'
                END AS active,
                COUNT(*) as count
            FROM $tbl_name
            GROUP BY active";
        }
        // tmp transitional
        global $db;
        $sth = $db->dbh->prepare($sql);
        // Test if there is an active column!
        try {
            $sth->execute();
        } catch(Exception $e) {
            if ($e->getCode() == "42S22" && strpos(strtolower($e->getMessage()),"unknown column")) {
                return $this->count_active($tbl_name, true);
            } else {
                throw $e;
            }
        }
        while($row = $sth->fetch(\PDO::FETCH_ASSOC))
            $result[$row['active']] = (int)$row['count'];
        $total = 0;
        return $result;
    }

    public function __runDemoTestSuite($rowset) {
        foreach($rowset as $row) {
            echo "<h4>\$row->toArray()</h4><pre>";
            print_r($row->toArray());
            echo "</pre>";

            // Doesn't work - so no worries.
            // echo "\$row key/value loop\n";
            // foreach($row as $key => $value) {
            //     echo "\t$key => $value\n";
            // }

            // Doesn't work - so no worries.
            // echo "array_keys(\$row)\n";
            // print_r(array_keys($row));

            echo "<h4>offset lookups</h4>";
            $keys = array_merge(array_keys($row->__getUncensoredDataForTesting()), array("this_is_a_fake_key"));
            echo "<ul>";
            foreach($keys as $key) {
                echo "<li><h5>$key</h5>";

                echo "<ul>";
                echo "<li>array_key_exists: ";
                $keyExists = array_key_exists($key, $row);
                var_dump($keyExists);

                echo "</li><li>property_exists: ";
                $propExists = property_exists($row, $key);
                var_dump($propExists);
                echo "</li>";

                echo "<li>\$row[$key]: ";
                try { var_dump($row[$key]); }
                catch(\ErrorException $e) { echo "<em>lookup threw ErrorException</em>"; }
                echo  "</li>";

                echo "<li>\$row->$key: ";
                try { var_dump($row->{$key}); }
                catch(\ErrorException $e) { echo "<em>lookup threw ErrorException</em>"; }
                catch(\InvalidArgumentException $e) { echo "<em>lookup threw InvalidArgumentException</em>"; }

                echo "</li>";
                echo "</ul>";
            }
            echo "</ul>";
        }
        exit;
    }

}
