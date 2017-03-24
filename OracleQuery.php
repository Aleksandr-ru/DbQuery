<?php
/**
 * Класс работы с Oracle
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 0.5 pre-beta
 * 
 * В основу положена концепция из ora_query() by alyuro
 * 
 * информация о версиях
 * 1.0 
 */
class OracleQuery
{
	const NLSLANG_RU_UTF8 = 'RUSSIAN_CIS.AL32UTF8';
	const NLSLANG_RU_1251 = 'RUSSIAN_CIS.CL8MSWIN1251';
	const VARTYPE_IN = ':';
	const VARTYPE_OUT = '&';
	const VARTYPE_CURSOR = '@';
	const VARCLASS_BLOB = 'blob';
	const VARCLASS_CLOB = 'clob';
	const BIND_MAXLENGTH = 255;	
	const SCHEMA_REGEXP = '/^[a-z]+[a-z0-9_]*$/i';
	
	protected $conn, $e;
	protected $lob, $curs;
	
	/**
	* режим извлечения данных 
	* @var $fetch_mode
	* 
	*/
	protected $fetch_mode = OCI_ASSOC;
	
	function __construct($username, $password, $db, $schema = '', $nls_lang = self::NLSLANG_RU_UTF8)
	{
		if($nls_lang) {
			$a = explode('.', $nls_lang);
			$charset = array_pop($a);
			@putenv("NLS_LANG=$nls_lang");
		} 
		else {
			$charset = null;
		}
		
		$this->conn = oci_connect($username, $password, $db, $charset);
		$this->e = oci_error();
		
		if(!$this->conn) {
			throw new Exception($this->e['message'] ? $this->e['message'] : 'Unknown error', $this->e['code'] ? $this->e['code'] : -1);
		}
		elseif($this->e['code']) {
			trigger_error("Connection was successful, but {$this->e['message']}", E_USER_WARNING);
		}
				
		if(preg_match(self::SCHEMA_REGEXP, $schema)) {
			$this->execQuery("ALTER SESSION SET CURRENT_SCHEMA = $schema");
		}
		elseif($schema) {
			trigger_error("Bad schema name '$schema' ignored", E_USER_WARNING);
		}
	}
	
	/**
	 * получить последнюю ошибку в виде массива
	 * @return array('code' => ..., 'message' => ..., 'offset' => ..., 'sqltext' => ...,)
	 */
	function getError()
	{
		return $this->e;
	}
	
	/**
	 * получить последний код ошибки
	 * @return int
	 */
	function getErrorCode()
	{
		return isset($this->e['code']) && $this->e['code'] ? $this->e['code'] : FALSE;
	}
	
	/**
	 * получить последнее сообщение об ошибке
	 * @return string
	 */
	function getErrorMes()
	{
		return isset($this->e['message']) ? $this->e['message'] : FALSE;
	}
	
	protected function freeLobCurs()
	{
		if(isset($this->lob)) foreach ($this->lob as $key => $value) {
			@oci_free_descriptor($value);
			unset($this->lob[$key]);
		}
		if(isset($this->curs)) foreach ($this->curs as $key => $value) {
			@oci_free_statement($value);
			unset($this->curs[$key]);
		}
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
	* @param int $mode новое значение может быть OCI_ASSOC, OCI_NUM
	* 
	* @return bool
	*/
	function setFetchMode($mode = OCI_ASSOC)
	{		
		$this->fetch_mode = OCI_ASSOC;
		return TRUE;
	}
	
	/**
	 * разбирает запрос и превращает в подходящий вид для oracle
	 * возвращает массив параметров переменных запроса, например
	 * @param string &$sql запрос вида Schema.Package.Procedure(:in_param, &out_param, &[2048]large_out_param, @cursor, :[blob]in_blob, &[blob]out_blob, :[clob]in_clob, &[clob]out_clob);
	 * @return Array
	 * (
	 *     [0] => Array( [0] => (:in_param [1] => &out_param [2] => &[2048]large_out_param [3] => @cursor [4] => :[blob]in_blob [5] => &[blob]out_blob [6] => :[clob]in_clob [7] => &[clob]out_clob )
	 *     [1] => Array( [0] => : [1] => & [2] => & [3] => @ [4] => : [5] => & [6] => : [7] => & )
	 *     [2] => Array( [0] => [1] => [2] => 2048 [3] => [4] => blob [5] => blob [6] => clob [7] => clob )
	 *     [3] => Array( [0] => in_param [1] => out_param [2] => large_out_param [3] => cursor [4] => in_blob [5] => out_blob [6] => in_clob [7] => out_clob)
	 * )
	 * 
	 */
	protected function parseQuery(&$sql)
	{
		if(substr($sql, -1) == ';' && !preg_match('/^begin/i', $sql)) $sql = "BEGIN $sql END;";
		if(FALSE === preg_match_all('/(?:[\W])(:|&|@)(?:\[(\d+|blob|clob)\])?([A-Za-z][A-Za-z_0-9]*)/', $sql, $variables)) {
			trigger_error("Failed to parse query [$sql]");
			return FALSE;
		}		
		$sql = preg_replace('/([\W])(:|&|@)(\[(\d+|blob|clob)\])?([A-Za-z][A-Za-z_0-9]*)/', '\1:\5', $sql);		
		return $variables;
	}
	
	/**
	 * делает bind пеерменной на основе указанных параметров
	 * при необходимости создает требуемые $this->lob[] и $this->cursor[]
	 * 
	 * @param resource $stmt
	 * @param string $var название переменной
	 * @param string $arg значение для bind
	 * @param string $vartype тип переменной, входная (:) или выходная (&)
	 * @param string $varclass класс blob или clob или размер для выходной переменной
	 */
	protected function makeBind($stmt, $var, &$arg, $vartype, $varclass)
	{
		// regular in
		if ($vartype == self::VARTYPE_IN && !$varclass) {			
			return oci_bind_by_name($stmt, $var, $arg);
		}
		// regular out
		elseif ($vartype == self::VARTYPE_OUT && preg_match("/^\d*$/", $varclass)) {
			$maxlength = intval($varclass);
			if(!$maxlength) $maxlength = self::BIND_MAXLENGTH;
			return oci_bind_by_name($stmt, $var, $arg, $maxlength);
		}
		// cursor
		elseif ($vartype == self::VARTYPE_CURSOR) {
			$this->curs[$var] = oci_new_cursor($this->conn);
			return oci_bind_by_name($stmt, $var, $this->curs[$var], -1, OCI_B_CURSOR);
		}
		// blob and clob in
		elseif ($vartype == self::VARTYPE_IN && ($varclass == self::VARCLASS_BLOB || $varclass == self::VARCLASS_CLOB)) {
			$this->lob[$var] = oci_new_descriptor($this->conn, OCI_D_LOB);
			if(method_exists($this->lob[$var], 'writeTemporary')) {
				$this->lob[$var]->writeTemporary($arg, $varclass == self::VARCLASS_BLOB ? OCI_TEMP_BLOB : OCI_TEMP_CLOB);
				return oci_bind_by_name($stmt, $var, $this->lob[$var], -1, $varclass == self::VARCLASS_BLOB ? OCI_B_BLOB : OCI_B_CLOB);
			}
			else {
				trigger_error("Method 'writeTemporary' does not exists for '$var'. OCI-Lob is '" . print_r($this->lob[$var], TRUE)."'", E_USER_WARNING);
				return FALSE;
			}
		}
		// blob and clob out
		elseif ($vartype == self::VARTYPE_OUT && ($varclass == self::VARCLASS_BLOB || $varclass == self::VARCLASS_CLOB)) {
			$this->lob[$var] = oci_new_descriptor($this->conn, OCI_D_LOB);				
			return oci_bind_by_name($stmt, $var, $this->lob[$var], -1, $varclass == self::VARCLASS_BLOB ? OCI_B_BLOB : OCI_B_CLOB);
		}
		// wrong
		else {
			trigger_error("Wrong bind parameters for '$var'", E_USER_WARNING);			
			return FALSE;
		}
	}

	/**
	 * обрабатывает результат и освобаждает затронутые $this->lob[] и $this->cursor[]
	 *
	 * @param string $var название переменной
	 * @param string $arg значение для bind
	 * @param string $vartype тип переменной, входная (:) или выходная (&)
	 * @param string $varclass класс blob или clob или размер для выходной переменной
	 * @return mixed или FALSE если операция не удалась
	 */
	protected function processResult($var, &$arg, $vartype, $varclass)
	{
		$ret = NULL;
		// regular out
		if ($vartype == self::VARTYPE_OUT && preg_match("/^\d*$/", $varclass)) {
			return $arg;
		}
		// cursor
		elseif ($vartype == self::VARTYPE_CURSOR) {
			if(!@oci_execute($this->curs[$var])) {
				$this->e = oci_error($this->curs[$var]);
				trigger_error("Cursor '$var' execution error: ".$this->getErrorMes(), E_USER_WARNING);
				$ret = FALSE;
			}
			else oci_fetch_all($this->curs[$var], $ret, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + $this->fetch_mode);
			oci_free_statement($this->curs[$var]);
		}
		// blob and clob in and out
		elseif ($varclass == self::VARCLASS_BLOB || $varclass == self::VARCLASS_CLOB) {
			if($vartype == self::VARTYPE_IN) {
				if(method_exists($this->lob[$var], 'flush')) {
					$this->lob[$var]->flush(OCI_LOB_BUFFER_FREE);					
				}
				else {
					$this->e = oci_error($this->conn);
					trigger_error("Method 'flush' does not exists for '$var'. OCI-Lob is '" . print_r($this->lob[$var], TRUE)."'", E_USER_WARNING);
					$ret = FALSE;
				}
			}
			else { // $vartype == self::VARTYPE_OUT
				if(is_null($this->lob[$var])) {
					// Yep! Out Lob can be NULL o_0
					$ret = NULL;
				}
				elseif(method_exists($this->lob[$var], 'load')) {
					// Lob is not NULL and data can be fetched
					$ret = $this->lob[$var]->load();
				}
				else {
					$this->e = oci_error($this->conn);
					trigger_error("Method 'load' does not exists for '$var'. OCI-Lob is '" . print_r($this->lob[$var], TRUE)."'", E_USER_WARNING);
					$ret = FALSE;
				}
			}
			if($this->lob[$var]) oci_free_descriptor($this->lob[$var]);			
		}
		return $ret;
	}


	/**
	 * выполнить процедуру и вернуть OUT параметры и курсоры в массиве
	 * @param string $sql запрос вида 'Schema.Package.Procedure(:in_param, &out_param, &[2048]large_out_param, @cursor, :[blob]in_blob, &[blob]out_blob, :[clob]in_clob, &[clob]out_clob);'
	 * @param mixed $bind1 переменная для первого bind
	 * @param mixed $...   ...
	 * @param mixed $bindN переменная для N bind
	 * 
	 * @return array('out_param' => ..., 'large_out_param' => ..., 'cursor' => array(...), 'out_blob' => ..., 'out_clob' => ...)
	 */
	function execProc($sql)
	{
		$variables = $this->parseQuery($sql);
		if(FALSE === $variables) return FALSE;
		
		$paramcount = sizeof($variables[0]);
		if($paramcount > func_num_args() - 1) {							
			throw new BadMethodCallException("Too less arguments for query [$sql]");
		}
		
		$stmt = oci_parse($this->conn, $sql);
		$this->e = oci_error($stmt);
		if(!$stmt) {
			trigger_error("Failed to parse sql [$sql]", E_USER_WARNING);
			return FALSE;
		}
		
		$args = func_get_args();
		array_shift($args);
		
		// make binds
		for($i=0; $i<$paramcount; $i++) if(!$this->makeBind($stmt, $variables[3][$i], $args[$i], $variables[1][$i], $variables[2][$i])) {
			$this->e = oci_error($stmt);
			oci_free_statement($stmt);
			trigger_error("Failed to make bind for '{$variables[3][$i]}' [$sql]", E_USER_WARNING);
			$this->freeLobCurs();
			return FALSE;	
		}
		
		// execute
		if(!oci_execute($stmt, OCI_DEFAULT)) {
			$this->e = oci_error($stmt);
			oci_free_statement($stmt);
			$this->freeLobCurs();
			trigger_error("Execute failed for query [$sql]", E_USER_WARNING);
			return FALSE;
		}
		
		$ret = array();
		
		// process result
		for($i=0; $i<$paramcount; $i++) {
			$var = $variables[3][$i];			
			$vartype = $variables[1][$i];
			$varclass = $variables[2][$i];
			$ret[$var] = $this->processResult($var, $args[$i], $vartype, $varclass);
			if(FALSE === $ret[$var]) {
				oci_rollback($this->conn);
				oci_free_statement($stmt);
				$this->freeLobCurs();
				trigger_error("Process result failed for query [$sql]", E_USER_WARNING);
				return FALSE;
			}
			elseif(NULL === $ret[$var] && self::VARTYPE_OUT != $vartype && self::VARTYPE_CURSOR != $vartype) {
				unset($ret[$var]);
			}
		}
		
		oci_commit($this->conn);
		oci_free_statement($stmt);
		return sizeof($ret) ? $ret : TRUE;
	}
	
	/**
	* подготовить statement с биндом переменных (для внутренних целей)
	* @param string $sql запрос с маркерами для бинда ':name'
	* @param mixed $bind1 переменная для первого bind
	* @param mixed $...   ...
	* @param mixed $bindN переменная для N bind
	* 
	* @return resource 	
	*/
	protected function stmtWithBind($sql)
	{		
		$variables = $this->parseQuery($sql);
		if(FALSE === $variables) return FALSE;		
		
		$paramcount = sizeof($variables[0]);
		if($paramcount > func_num_args() - 1) {							
			throw new BadMethodCallException("Too less arguments for query [$sql]");
		}
		
		$stmt = oci_parse($this->conn, $sql);
		$this->e = oci_error($stmt);
		if(!$stmt) {
			trigger_error("Failed to parse sql [$sql]", E_USER_WARNING);
			return FALSE;
		}
		
		// make binds
		for($i=0; $i<$paramcount; $i++) {
			$arg = func_get_arg($i+1);			
			if(!$this->makeBind($stmt, $variables[3][$i], $arg, $variables[1][$i], $variables[2][$i])) {
				$this->e = oci_error($stmt);
				oci_free_statement($stmt);
				trigger_error("Failed to make bind for '{$variables[3][$i]}' [$sql]", E_USER_WARNING);
				return FALSE;	
			}
		}
		
		return $stmt;
	}
	
	/**
	* выполнить запрос не возвращающий данных
	* @param string $sql запрос вида 'insert into t (col1, col2) VALUES(:bind1, :bind2)'
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
		
		// execute
		if(!oci_execute($stmt, OCI_DEFAULT)) {
			$this->e = oci_error($stmt);
			oci_free_statement($stmt);
			trigger_error("Execute failed for query [$sql]", E_USER_WARNING);
			return FALSE;
		}		
		oci_free_statement($stmt);
		return TRUE; 		
	}
	
	/**
	* получить результат выполнения запроса в массив
	* @param string $sql запрос вида 'select * from t where a = :bind1 AND b = :bind2'
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
		
		// execute
		if(!oci_execute($stmt, OCI_DEFAULT)) {
			$this->e = oci_error($stmt);
			oci_free_statement($stmt);
			trigger_error("Execute failed for query [$sql]", E_USER_WARNING);
			return FALSE;
		}
		
		// fetch select
		$fetch = oci_fetch_all($stmt, $results, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + $this->fetch_mode);
		$this->e = oci_error($stmt);  
		oci_free_statement($stmt);
		return $fetch && sizeof($results) ? $results: FALSE; 
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
}
?>