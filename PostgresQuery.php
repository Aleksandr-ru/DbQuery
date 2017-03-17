<?php
/**
 * Класс работы с PostgreSQL
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 0.2 beta
 *
 * информация о версиях
 * 1.0
 */
class PostgresQuery
{
	protected $conn;
	protected $affected_rows = 0;
    
	function __construct($host = 'localhost:5432', $dbname = '', $user = '', $password = '')
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
	 * парсит SQL запрос заменяя ? на $1
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
			throw new BadMethodCallException("Too less arguments for query [$sql_orig]");
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
	 * BEGIN
	 * @return bool
	 */
	function beginTransaction()
	{
		return $this->execQuery('begin');
	}

	/**
	 * COMMIT
	 * @return bool
	 */
	function commitTransaction()
	{
		return $this->execQuery('commit');
	}

	/**
	 * ROLLBACK
	 * @return bool
	 */
	function rollbackTransaction()
	{
		return $this->execQuery('rollback');
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
			$data = pg_fetch_all($result);
			foreach($data as &$row) foreach($row as $field_name => &$value) {
				$field_number = pg_field_num($result, $field_name);
				$field_type = pg_field_type($result, $field_number);				
				if($field_type == 'bool') $value = ($value == 't');
				elseif(substr($field_type, 0, 3) == 'int') $value = (int)$value;
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
}
