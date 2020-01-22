<?php
/**
 * Класс работы с PostgreSQL
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 1.1
 *
 * информация о версиях
 * 1.0
 * 1.1 Добавлены вложенные транзакции
 */
class PostgresQuery
{
	const SCHEMA_REGEXP = '/^[a-z]+[a-z0-9_]*$/i';

	protected $conn;
	protected $affected_rows = 0;
	protected $transaction = false;
    protected $level = 0;

	function __construct($host = 'localhost:5432', $dbname = '', $user = '', $password = '', $schema = '')
	{
		$conn_string = array();
		@list($host, $port) = explode(':', $host);
		if($host) $conn_string[] = "host=$host";
		if($port) $conn_string[] = "port=$port";
		if($dbname) $conn_string[] = "dbname=$dbname";
		if($user) $conn_string[] = "user=$user";
		if($password) $conn_string[] = "password=$password";
		$str = implode(' ', $conn_string);
		$this->conn = pg_connect($str);
		if(!$this->conn) {
			if($password) {
				$conn_string[count($conn_string)-1] = "password=***";
			}
			$str = implode(' ', $conn_string);
			throw new Exception("Failed to connect to '$str'");
		}

		if(preg_match(self::SCHEMA_REGEXP, $schema)) {
			$this->execQuery("SET SESSION search_path TO $schema;");
		}
		elseif($schema) {
			trigger_error("Bad schema name '$schema' ignored", E_USER_WARNING);
		}
	}

	function __destruct()
    {
        if ($this->conn && $this->transaction) {
            // если есть незавершенная транзакция
            //TODO: поведение по-умолчанию rollback или сommit ?
            $this->execQuery('rollback');
        }
    }

    /**
	 * получить последнее сообщение об ошибке
	 * @return string
	 */
	function getError()
	{
		return pg_last_error($this->conn);
	}
	
	/**
	 * парсит SQL запрос на предмет вхождения IN($1) и заменяет '$1' на нужное количество в зависимости от значений bind параметров
	 * также преобразовывает массивы в значениях bind параметров в дополнительные элементы
	 * @param string $sql запрос
	 * @param array $args bind параметры
	 * @return boolean были или нет замены в запросе/параметрах
	 * @throws BadMethodCallException когда размер $args меньше чем требуется SQL запросу
	 */
	protected static function parseSqlIn(&$sql, &$args)
	{
		if(!preg_match("/IN\s*\(\s*\\$\d+\s*\)/i", $sql)) return FALSE;
		$sql_orig = $sql;
		$cnt = -1;
		$sql = preg_replace_callback("/((?P<in>IN)\s*\(\s*(?P<s>\\$\d+)\s*\))|(\W\\$\d+)/i", function ($matches) use(&$args, &$cnt, $sql_orig) {
			$cnt++;
			if(!array_key_exists($cnt, $args)) throw new BadMethodCallException("Too less arguments for query [$sql_orig]");
			if($matches['in'] && is_array($args[$cnt])) {
				$size = count($args[$cnt]);
				if($size > 1) {
					$in = 'IN(' . $matches['s'];
					for($i=1; $i<$size; $i++) {
						$ii = count($args) + 1;
						$args[] = $args[$cnt][$i];
						$in .= ', $' . $ii;
					}
					$args[$cnt] = array_shift($args[$cnt]);
					$in .= ')';
					return $in;
				}
				elseif($size == 1) $args[$cnt] = array_shift($args[$cnt]);
				else $args[$cnt] = NULL;
			}
			return $matches[0];
		}, $sql);
		return ($cnt >= 0);
	}

	/**
	 * парсит SQL запрос заменяя ? на $1,
	 * приводит булевские типы к 0 или 1,
	 * объекты к json-строкам
	 * @param string $sql запрос
	 * @param array $args bind параметры
	 * @return boolean были или нет замены в запросе/параметрах
	 * @throws BadMethodCallException когда размер $args меньше чем требуется SQL запросу
	 */
	protected static function parseSql(&$sql, &$args)
	{
		$sql_orig = $sql;
		$cnt = 0;
		$sql = preg_replace_callback("/\W\?/", function ($matches) use(&$cnt) {
			return substr($matches[0], 0, -1) . '$' . ++$cnt;
		}, $sql);
		if(count($args) != $cnt) {
			throw new BadMethodCallException("Wrong number of arguments for query [$sql_orig]");
		}
		foreach($args as &$a) {
			if(is_bool($a)) $a = $a ? 1 : 0;
			elseif(is_object($a)) $a = json_encode($a);
		}
		return self::parseSqlIn($sql, $args);
	}

	/**
	 * выполнить запрос не возвращающий данных
	 * @param string $sql запрос вида 'insert into t (col1, col2) VALUES(?, ?)'
	 * @param mixed $bind1 переменная для первого bind
	 * @param mixed $...   ...
	 * @param mixed $bindN переменная для N bind
	 *
	 * @return bool
	 */
	function execQuery($sql)
	{
		$args = array_slice(func_get_args(), 1);
		self::parseSql($sql, $args);
		
		if($result = pg_query_params($this->conn, $sql, $args)) {
			$this->affected_rows = pg_affected_rows($result);		
			return TRUE;
		}
		else return FALSE;
	}

    /**
     * Выполнить множество запросов без параметров
     * @see https://www.php.net/manual/en/function.pg-query.php#example-2613
     * @param string $sql
     * @return bool
     */
    function execMultipleQuery($sql)
    {
        if ($result = pg_query($this->conn, $sql)) {
            $this->affected_rows = pg_affected_rows($result);
            return TRUE;
        }
        else return FALSE;
    }

	/**
	 * BEGIN
	 * @return bool
	 */
	function beginTransaction()
	{
		if ($this->level === 0) {
		    $this->transaction = true;
		    $this->execQuery('begin');
		}
		$this->level++;
		return $this->execQuery(sprintf("SAVEPOINT level_%s", $this->level));
	}

	/**
	 * COMMIT
	 * @return bool
	 */
	function commitTransaction()
	{
		$this->execQuery(sprintf("RELEASE SAVEPOINT level_%s", $this->level));
        $this->level--;
        if ($this->level === 0) {
            $this->transaction = false;
            return $this->execQuery('commit');
        }

        return true;
	}

	/**
	 * ROLLBACK
	 * @return bool
	 */
	function rollbackTransaction()
	{
		$result = $this->execQuery(sprintf("ROLLBACK TO SAVEPOINT level_%s", $this->level));
        $this->level--;
        if ($this->level === 0) {
            $this->transaction = false;
            return $this->execQuery('rollback');
        }

        return $result;
	}

	/**
	 * получить результат выполнения запроса в массив
	 * @param string $sql запрос вида SELECT * FROM t WHERE a = ? AND b = ?
	 * @param mixed $bind1 переменная для первого bind
	 * @param mixed $...   ...
	 * @param mixed $bindN переменная для N bind
	 *
	 * @return mixed если запрос что-то возвращает, то массив значений по рядам из БД, FALSE в остальных случаях
	 */
	function queryArray($sql)
	{
		$args = array_slice(func_get_args(), 1);
		self::parseSql($sql, $args);

		if($result = pg_query_params($this->conn, $sql, $args)) {
			$this->affected_rows = pg_affected_rows($result);
			$data = pg_fetch_all($result) or $data = array();
			$field_types = array();
			$field_number = 0;
			foreach($data as &$row) foreach($row as $field_name => &$value) {
				if(sizeof($field_types) < sizeof($row)) {
					$field_types[$field_name] = pg_field_type($result, $field_number++);
				}
				$field_type = $field_types[$field_name];
				if(!is_null($value)) {
					if($field_type == 'bool') $value = ($value == 't');
					elseif(substr($field_type, 0, 3) == 'int') $value = (int)$value;
					elseif(substr($field_type, 0, 4) == 'json') $value = json_decode($value);
				}
			}
			return $data;
		}
		else return FALSE;
	}

	/**
	 * получить первую строку из резултатов запроса
	 * @see queryArray
	 */
	function queryRow($sql)
	{
		$ret = call_user_func_array(array($this, 'queryArray'), func_get_args());
		if(is_array($ret) && sizeof($ret)) return array_shift($ret);
		else return $ret;
	}

	/**
	 * получить первое значение из первого ряда результатов зпроса
	 * @see queryRow
	 * @see queryArray
	 */
	function queryValue($sql)
	{
		$ret = call_user_func_array(array($this, 'queryRow'), func_get_args());
		if(is_array($ret) /*&& sizeof($ret)*/) return array_shift($ret);
		else return $ret;
	}

	/**
	 * получить первую полонку из результатов запроса
	 * если в результате более одной колонки, то возвращается массив(col1 => col2, ...)
	 * @see queryArray
	 */
	function queryColumn($sql)
	{
		$a = call_user_func_array(array($this, 'queryArray'), func_get_args());
		$ret = array();
		if(is_array($a) && sizeof($a)) foreach($a as $b) {
			$b = array_values($b);
			@list($col1, $col2) = $b;
			isset($col2) ? $ret[$col1] = $col2 : $ret[] = $col1;
		}
		else return $a;
		return $ret;
	}

	/**
	 * Возвращает количество кортежей (сущностей/записей/рядов) затронутых последним запросом
	 * @return int
	 */
	function getAffectedRows()
	{
		return $this->affected_rows;
	}
}
