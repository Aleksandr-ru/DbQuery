# DbQuery
Simple interface to common databses
```php
$sql = "INSERT INTO t (col1, col2) VALUES(?, ?)";
$DB->execQuery($sql, $bind1, $bind2); 

$sql = "SELECT * FROM t WHERE a = ? AND b = ?";
$arr = $DB->queryArray($sql, $a, $b); 
// all data as array of associative rows

$sql = "SELECT * FROM t WHERE id = ?";
$arr = $DB->queryRow($sql, $id); 
// first row, all columns (associative)

$sql = "SELECT somefield FROM t WHERE id = ?";
$val = $DB->queryValue($sql, $id); 
// first row, first column

$sql = "SELECT login, name FROM t WHERE id > ? AND id < ?";
$arr = $DB->queryColumn($sql, $min_id, $max_id); 
// all rows, first column (or first and second, depends on columns count in select)
```

Simplified procedure call for Oracle

```php
$sql = "Package.Procedure(:in_param, &out_param, &[2048]large_out_param, @cursor, :[blob]in_blob, &[blob]out_blob, :[clob]in_clob, &[clob]out_clob);";
$result = $DB->execProc($sql, $in_param, null, null, null, $in_blob, null, $in_clob, null);
// procedure execution result array(
//   'out_param' => "out parameter value",
//   'large_out_param' => "out parameter value up to 2048 characters",
//   'cursor' => array( 0 => array( 'column_name' => "column value", ...), ...),
//   'out_blob' => "blob binary data",
//   'out_clob' => "clob data"
// )
```



## Supported databases

**MySQL** via mysqli

**SQLite** via sqlite3

**Oracle** via oci8

**PostgreSQL** via pgsql

