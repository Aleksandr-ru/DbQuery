<?php
/**
 * Класс работы с SQLite
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 1.1
 * 
 * информация о версиях
 * 1.0 
 * 1.1 добавлены output callback и fetch mode
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
	* подготовить statement с биндом переменных (для внутренних целей)
	* @param string $sql запрос с маркерами для бинда '?'
	* @param mixed $bind1 переменная для первого bind
	* @param mixed $...   ...
	* @param mixed $bindN переменная для N bind
	* 
	* @return SQLite3Stmt 	
	*/
	protected function stmtWithBind($sql)
	{
		// готовим stmt
		$stmt = $this->prepare($sql);
		if(!$stmt) {
			trigger_error("Failed to prepare statement for [$sql]", E_USER_WARNING);
			return FALSE;
		}
		// проверяем количество параметров		
		if($stmt->paramCount() > func_num_args() - 1) {			
			//trigger_error("Too less parameters for query [$sql]", E_USER_WARNING);
			$stmt->close();
			//return FALSE;
			throw new BadMethodCallException("Too less arguments for query [$sql]");
		}
		// bind паарметров
		//for($i = 1; $i < func_num_args(); $i++) {
		for($i = 1; $i <= $stmt->paramCount(); $i++) {
			$arg = func_get_arg($i);
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
		if(is_array($ret) && sizeof($ret)) return array_shift($ret);
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