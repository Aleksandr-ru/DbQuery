# DbQuery
Simple interface to common databses
```
$sql = "INSERT INTO t (col1, col2) VALUES(?, ?)";
$DB->execQuery($sql, $bind1, $bind2); 

$sql = "SELECT * FROM t WHERE a = ? AND b = ?";
$DB->queryArray($sql, $a, $b); // all data

$sql = "SELECT * FROM t WHERE id = ?";
$DB->queryRow($sql, $id); // first row, all columns

$sql = "SELECT somefield FROM t WHERE id = ?";
$DB->queryValue($sql, $id); // first row, first column

$sql = "SELECT login, name FROM t WHERE id > ? AND id < ?";
$DB->queryColumn($sql, $min_id, $max_id); // first column (first and second, depends on select), all rows
```

## Supported databases

**MySQL** via mysqli

**SQLte** via sqlite3
