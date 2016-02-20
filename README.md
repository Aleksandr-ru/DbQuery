# DbQuery
Simple interface to common databses
```
$sql = "INSERT INTO t (col1, col2) VALUES(?, ?)";
$DB->execQuery($sql, $bind1, $bind2); 

$sql = "SELECT * FROM t WHERE a = ? AND b = ?";
$arr = $DB->queryArray($sql, $a, $b); // all data

$sql = "SELECT * FROM t WHERE id = ?";
$arr = $DB->queryRow($sql, $id); // first row, all columns

$sql = "SELECT somefield FROM t WHERE id = ?";
$val = $DB->queryValue($sql, $id); // first row, first column

$sql = "SELECT login, name FROM t WHERE id > ? AND id < ?";
$arr = $DB->queryColumn($sql, $min_id, $max_id); // all rows, first column (or first and second, depends on select)
```

## Supported databases

**MySQL** via mysqli

**SQLte** via sqlite3

**Oracle** via oci8 (testing stage)
