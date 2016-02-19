<?php
/**
 * Класс работы с MySQLi
 * @copyright (c)Rebel http://aleksandr.ru
 * @version 1.3
 * 
 * информация о версиях
 * 1.0 
 * 1.1 добавлены output callback и fetch mode
 * 1.2 поддержка работы через MySQL Client Library (libmysqlclient) если отсутсвует MySQL Native Driver (mysqlnd)
 * 1.3 контроль количества параметрв для bind и поддержка php 5.3+
 */
class MySQLiQuery extends mysqli
{
    const OUTPUTCALLBACK_NONE = FALSE;
    const OUTPUTCALLBACK_HTML = 'htmlspecialchars';
    
    /**
    * @var $output_callback callback функция для выводимх значений из БД
    */
    protected $output_callback = self::OUTPUTCALLBACK_NONE;
    
    /**
    * @var $fetch_mode режим извлечения данных 
    */
    protected $fetchMode = MYSQLI_ASSOC;
    
    /**
     * @var $stmt_affected_rows хранилище количества затронутых рядов от последней операции через statement
     */
    protected $stmt_affected_rows = 0;
    
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
    * @see MySQLiQuery::OUTPUTCALLBACK_* const
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
    * @param int $mode новое значение может быть MYSQLI_ASSOC, MYSQLI_NUM, MYSQLI_BOTH
    * 
    * @return bool
    */
    function setFetchMode($mode = MYSQLI_ASSOC)
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
    * @return mysqli_stmt   
    */
    function stmtWithBind($sql)
    {
        $stmt = $this->prepare($sql);
        if(!$stmt) {
            trigger_error($this->error." [$sql]");
            return FALSE;
        }
        if($stmt->param_count > func_num_args() - 1) {						
			$stmt->close();			
			throw new BadMethodCallException("Too less arguments for query [$sql]");
		}		
        if($stmt->param_count > 0) {
            $params = func_get_args();
            $params[0] = '';            
            for($i = 1; $i <= $stmt->param_count; $i++) {                
                $params[0] .= $this->getArgType($params[$i]);
                $params[$i] = &$params[$i];
            }            
            if(!call_user_func_array(array($stmt, 'bind_param'), $params)) {
                $stmt->close();
                trigger_error("Failed to bind params.", E_USER_WARNING);
                return FALSE;
            }
        }
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
        if(!$stmt->execute()) {
            $stmt->close();
            trigger_error("Failed to execute.", E_USER_WARNING);
            return FALSE;
        }
        $this->stmt_affected_rows = $stmt->affected_rows;
        $stmt->close();
        return TRUE;
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
        $ret = array();
        $stmt = call_user_func_array(array($this, 'stmtWithBind'), func_get_args());
        if(!$stmt) {
            return FALSE;
        }
        if(!$stmt->execute()) {
            $stmt->close();
            trigger_error("Failed to execute.", E_USER_WARNING);
            return FALSE;
        }
        $this->stmt_affected_rows = $stmt->affected_rows;
        
        // If you don't have mysqlnd installed/loaded whatever, you will get an undefined reference when trying to call "mysqli_stmt_get_result()".
        if(method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            while($row = $result->fetch_array($this->fetchMode)) $ret[] = $this->output_callback ? array_map($this->output_callback, $row) : $row;    
        }
        else {
            // http://php.net/manual/en/mysqli-stmt.bind-result.php
            $stmt->store_result();
            $variables = array();
            $data = array();
            $meta = $stmt->result_metadata();
            while($field = $meta->fetch_field()) $variables[] = &$data[$field->name];
            call_user_func_array(array($stmt, 'bind_result'), $variables);
            $i=0;
            while($stmt->fetch()) {
                // don't know why, but when I tried $array[] = $data, I got the same one result in all rows
                $ret[$i] = array();
                $ii = 0;
                foreach($data as $k=>$v) {
                    switch($this->fetchMode) {
                        case MYSQLI_BOTH:
                            $ret[$i][$k] = $v;
                            $ret[$i][$ii] = $v;
                            break;
                        case MYSQLI_NUM:
                            $ret[$i][$ii] = $v;
                            break;
                        case MYSQLI_ASSOC:
                        default:   
                            $ret[$i][$k] = $v;
                            break;
                    }
                    $ii++;
                    if($this->output_callback) $ret[$i] = array_map($this->output_callback, $ret[$i]);
                }
                $i++;
            }
            $stmt->free_result();    
        }
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
     * плучить количество затронутых рядов от последней операции через statement
     * вместо использования $this->affected_rows который очищается после закрытия stmt
     * @return integer
     */
    function affectedRows()
    {
        return $this->stmt_affected_rows;
    }
    
    /**
    * получить тип аргумента для использрвания в bind_param
    * @param mixed $arg
    * 
    * @return string
    * @throws InvalidArgumentException
    */
    static function getArgType($arg)
    {
        switch ($type = gettype($arg))
        {
            case 'double': 
                return 'd';
            case 'integer':                 
            case 'boolean': 
                return 'i';
            case 'NULL': 
            case 'string': 
                return 's';
            default:
                throw new InvalidArgumentException("Argument is of invalid type $type");
        }
    }
}
?>