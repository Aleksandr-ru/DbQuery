<?php
/**
 * Класс работы с SQLite
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 1.2
 * 
 * информация о версиях
 * 1.0 
 * 1.1 добавлены output callback и fetch mode
 * 1.2 парсинг IN(?) и разбор массивов для IN
 */
class SQLiteQuery extends SQLite3
{
	const OUTPUTCALLBACK_NONE = FALSE;
	const OUTPUTCALLBACK_HTML = 'htmlspecialchars';
	
	/**
	* callback функция для выводимх значений из БД
	* @var $output_callback
	* 
	*/
	protected $output_callback = self::OUTPUTCALLBACK_NONE;
	
	/**
	* режим извлечения данных 
	* @var $fetch_mode
	* 
	*/
	protected $fetch_mode = SQLITE3_ASSOC;
	
	/**
	* получить текущий режим экранирования выводимых данных для query*-функций
	* 
	* @return callback
	*/
	function getOutputCallback()
	{
		return $this->output_callback;
	}
	
	/**
	* изменить режим экранирования выводимых данных
	* @param string $callback функция которой будет экранироваться весь вывод из query*-функций
	* если FALSE или пусто то без экранирования
	* 
	* @return bool
	* @see SQLiteQuery::OUTPUTCALLBACK_* const
	*/
	function setOutputCallback($callback = self::OUTPUTCALLBACK_NONE)
	{		
		if($callback && !is_callable($callback)) {
			trigger_error("Function '".print_r($callback, TRUE)."' does not exists!", E_USER_WARNING);
			return FALSE;
		}
		$this->output_callback = $callback;
		return TRUE;
	}
	
	/**
	* получить текущий режим извлечения данных для query*-функций
	* 
	* @return
	*/
	function getFetchMode()
	{		
		return $this->fetch_mode;
	}
	
	/**
	* устанавлливает режим извлечения данных для query*-функций
	* @param int $mode новое значение может быть SQLITE3_ASSOC, SQLITE3_NUM, SQLITE3_BOTH
	* 
	* @return bool
	*/
	function setFetchMode($mode = SQLITE3_ASSOC)
	{		
		$this->fetch_mode = $mode;
		return TRUE;
	}

	/**
	 * парсит SQL запрос на предмет вхождения IN(?) и заменяет '?' на нужное количество в зависимости от значений bind параметров
	 * также преобразовывает массивы в значениях bind параметров в дополнительные элементы
	 * @param string $sql запрос
	 * @param array $args bind параметры
	 * @return boolean были или нет замены в запросе/параметрах
	 * @throws BadMethodCallException когда размер $args меньше чем требуется SQL запросу
	 */
	protected static function parseSqlIn(&$sql, &$args)
	{
		if(!preg_match("/IN\s*\(\s*\?\s*\)/i", $sql)) return FALSE;
		$cnt = -1;
		$sql = preg_replace_callback("/((?P<in>IN)\s*\(\s*\?\s*\))|(\W\?)/i", function ($matches) use(&$args, &$cnt, $sql) {
			$cnt++;
			if(!array_key_exists($cnt, $args)) {
				throw new BadMethodCallException("Too less arguments for query [$sql]");
			}
			if($matches['in'] && is_array($args[$cnt])) {
				$size = sizeof($args[$cnt]);
				if($size > 1) {
					array_splice($args, $cnt, 1, $args[$cnt]);
					$cnt += $size-1;
					return 'IN(' . rtrim(str_repeat('?,', $size), ',') . ')';
				}
				elseif($size == 1) $args[$cnt] = array_shift($args[$cnt]);
				else $args[$cnt] = NULL;
			}
			return $matches[0];
		}, $sql);
		return TRUE;
	}
	
	/**
	* подготовить statement с биндом переменных (для внутренних целей)
	* @param string $sql запрос с маркерами для бинда '?'
	* @param mixed $bind1 переменная для первого bind
	* @param mixed $...   ...
	* @param mixed $bindN переменная для N bind
	* 
	* @return SQLite3Stmt
	* @throws BadMethodCallException когда количество параметров не соответсвует SQL запросу
	*/
	protected function stmtWithBind($sql)
	{
		$args = array_slice(func_get_args(), 1);
		self::parseSqlIn($sql, $args);

		// готовим stmt
		$stmt = $this->prepare($sql);
		if(!$stmt) {
			trigger_error("Failed to prepare statement for [$sql]", E_USER_WARNING);
			return FALSE;
		}
		// проверяем количество параметров				
		if($stmt->paramCount() > sizeof($args)) {
			$stmt->close();			
			throw new BadMethodCallException("Too less arguments for query [$sql]");
		}
		// bind паарметров		
		for($i = 1; $i <= $stmt->paramCount(); $i++) {			
			$arg = $args[$i-1];
			if(!$stmt->bindValue($i, $arg, $this->getArgType($arg))) {
				trigger_error("Failed to bind paremeter $i to [$sql]", E_USER_WARNING);
				return FALSE;
			}
		}
		// возвращаем
		return $stmt;
	}
	
	/**
	* выполнить запрос не возвращающий данных
	* @param string $sql запрос вида INSERT INTO t (col1, col2) VALUES(?, ?)
	* @param mixed $bind1 переменная для первого bind
	* @param mixed $...   ...
	* @param mixed $bindN переменная для N bind
	* 
	* @return bool	
	*/
	function execQuery($sql)
	{		
		$stmt = call_user_func_array(array($this, 'stmtWithBind'), func_get_args());
		if(!$stmt) {
			return FALSE;
		}		
		if($result = $stmt->execute()) {
			$result->finalize();
			$stmt->close();			
			return TRUE;
		}
		$stmt->close();
		return FALSE;
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
		$stmt = call_user_func_array(array($this, 'stmtWithBind'), func_get_args());
		if(!$stmt) {
			return FALSE;
		}
		$result = $stmt->execute();
		if(!$result) {			
			trigger_error("Failed to execute [$sql]", E_USER_WARNING);
			return FALSE;
		}				
		$ret = array();
		while($row = $result->fetchArray($this->fetch_mode)) {			
			$ret[] = $this->output_callback ? array_map($this->output_callback, $row) : $row;
		}		
		$result->finalize();
		$stmt->close();				
		return $ret;
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
	* получить тип аргумента для использрвания в bindValue
	* @param mixed $arg
	* 
	* @return SQLITE3_
	* @throws InvalidArgumentException
	*/
	static function getArgType($arg)
	{
	    switch ($type = gettype($arg))
	    {
	        case 'double': 
	        	return SQLITE3_FLOAT;
	        case 'integer': 	        	
	        case 'boolean': 
	        	return SQLITE3_INTEGER;
	        case 'NULL': 
	        	return SQLITE3_NULL;
	        case 'string': 
	        	return SQLITE3_TEXT;
	        default:
	            throw new InvalidArgumentException("Argument is of invalid type $type");
	    }
	}
}
?>