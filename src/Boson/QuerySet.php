<?php
namespace Lepton\Boson;

use Lepton\Base\Application;
use Lepton\Base\Database;
use mysqli_stmt;


/**
 * QuerySet is lazy: it doesn't execute queries unless forced.
 * It tries to build only one big query in order to accomplish the task.
 */

class QuerySet
  implements \Iterator, \ArrayAccess{

  // Variables used for Iterator implementation

  /**
   * The index of the current element in iteration
   *
   * @var int
   */
  private int $index;

  /**
   * The current element in iteration
   *
   * @var mixed
   */
  private mixed $current;

  /**
   * Check is iteration is valid
   *
   * @var bool
   */
  private bool $valid;


  /**
   * Array containing the filters to use in the WHERE clause of the query
   *
   * @var array
   */
  public array $filters;

  /**
   * Other modifiers for the query (e.g.) GROUP BY, ORDER BY
   *
   * @var array
   */
  public array $modifiers;

  /**
   * Dictionary between operator after __ in filter name and MySql operators
   * Every element has a key, corresponding to the string after __ in filter name.
   * Every element is an array of two element: the first has key "operator" and the value
   * is a string corresponding to the MySql operator; the second is an inline function
   * that accepts a parameter (the value) and returns a string corresponding to the r
   * right-hand side of the statament.
   * @var array
   */
  private array $lookup_map;


  /**
   * If query is executed, contains the mysqli_result object
   * If query is not executed, is NULL.
   *
   * @var \mysqli_result
   */
  private \mysqli_result $result;

  /**
   * If query is executed, contains the cached values (that is, the values already
   * retrieved from database).
   * If query is not executed, is NULL
   *
   * @var array
   */
  private array $cache;

  public function __construct(protected string $model){
    $this->lookup_map = array(
      "equals" => [
        "operator" => "=",
        "rhs" => fn($x) =>  $x
      ],
      "startswith" => [
        "operator" => "LIKE",
        "rhs" => fn($x) =>  sprintf('%s%%', $x)
      ],

      "endswith" => [
        "operator" => "LIKE",
        "rhs" => fn($x) => sprintf('%%%s', $x)
      ],

      "contains" => [
        "operator" => "LIKE",
        "rhs" => fn($x) => sprintf('%%%s%%', $x)
      ],

      "lte" => [
        "operator" => "<=",
        "rhs" => fn($x) => $x
      ],

      "gte" => [
        "operator" => ">=",
        "rhs" => fn($x) => $x
      ],

      "lt" => [
        "operator" => "<",
        "rhs" => fn($x) => $x
      ],

      "gt" => [
        "operator" => ">",
        "rhs" => fn($x) => $x
      ],

      "neq" => [
        "operator" => "<>",
        "rhs" => fn($x) => $x
      ]
    );

    $this->filters = array();
    $this->modifiers = array();

  }

  /*
  ================================== ITERATOR ==========================================
  */

  /**
   * Implements \Iterator interface.
   *
   * @return mixed
   * The current element of iteration
   */
  public function current(): mixed{
    return $this->current;
  }


  /**
   * Implements \Iterator interface.
   *
   * @return mixed
   * The key of the current element of iteration
   */
  public function key(): mixed{
    return $this->index;
  }

  /**
   * Implements \Iterator interface.
   * Gets next element of iteration.
   *
   * @return void
   */
  public function next(): void{
    $this->fetchNext();
  }


  /**
   * Implements \Iterator interface.
   * Rewinds the iteration.
   *
   * @return void
   */
  public function rewind(): void{
    $this->index = -1;
    if(! isset($this->result)){
      $this->cache = array();
      $this->do();
    }
    $this->fetchNext();
  }


  /**
   * Implements \Iterator interface
   *
   * @return bool
   * Whether the iteration is valid or not
   */

  public function valid(): bool{
    return $this->valid;
  }



  /**
   * Performs actual iteration step
   *
   *  @return void
   */
  protected function fetchNext() : void{
    // Get next row of the query result
    $items = $this->result->fetch_assoc();

    // If no element is retrieved, then iteration is not valid
    $this->valid = !is_null(($items));

    // If iteration is valid, update $this->current, $this->index

    $this->current = $this->valid ? ($this->model)::new(...$items) : NULL;
    $this->index = $this->valid ? $this->current->getPk() : -1;

    // Cache the retrieved element
    $this->cache[$this->index] = $this->current;
  }





  /*
  ================================= ARRAY ACCESS =======================================
  */



  /**
   * Implements \ArrayAccess interface.
   * Verifies if a key exists or not. If iteration is not started yet, throws an
   * Exception.
   *
   * @param mixed $offset
   * The key to verify
   *
   * @return bool
   * Whether the key exists
   */
  public function offsetExists(mixed $offset): bool{
    if(isset($this->result)){
      return array_key_exists($offset, $this->cache);
    } else {
      throw new \Exception("Before accessing keys, you have to execute query");
    }
  }



  /**
   * Implements \ArrayAccess interface.
   * Gets the element corresponding to the given key.
   *
   * @param mixed $offset
   * The key of the element to retrieve
   *
   * @return mixed
   */
  public function  offsetGet(mixed $offset): mixed{
    if(isset($this->result)){
      return $this->cache[$offset];
    } else {
      throw new \Exception("Before accessing keys, you have to execute query");
    }
  }


  /**
   * Refuse to set: QuerySets are read-only.
   */
  public function offsetSet(mixed $offset, mixed $value): void{
    throw new \Exception("QuerySets are read-only");
  }


  /**
   * Refuse to set: QuerySets are read-only.
   */
  public function offsetUnset(mixed $offset): void{
    throw new \Exception("QuerySets are read-only");
  }


  /*
  ============================== DATABASE FUNCTIONS ====================================
  */


  private function extract_filters($filters) : array{
    if((count($filters) == 1) && ($filters[array_key_first($filters)] instanceof QuerySet)){
      return $filters[array_key_first($filters)]->filters;
    } else if(count(array_filter($filters, fn($x) => $x instanceof QuerySet)) > 0){
      throw new \Exception("Only one element allowed if QuerySet");
    } else {
      return $filters;
    }
  }

  public function filter(...$filters): QuerySet{
    $this->and(...$filters);
    return $this;
  }


  public function and(...$filters): QuerySet{
    $this->filters[] = ["AND"  => $this->extract_filters($filters)];
    return $this;
  }


  public function exclude(...$filters): QuerySet{
    $this->filters[] = ["AND NOT"  => $this->extract_filters($filters)];
    return $this;
  }





  public function or(...$filters): QuerySet{
    $this->filters[] = ["OR"  => $this->extract_filters($filters)];
    return $this;
  }


  public function xor(...$filters): QuerySet{
    $this->filters[] = ["XOR"  => $this->extract_filters($filters)];
    return $this;
  }


  public function group_by(string ...$filters): QuerySet{
    if(array_key_exists("GROUP BY", $this->modifiers)){
      throw new \Exception("Multiple Group By!");
    }
    $this->modifiers["GROUP BY"] = $filters;
    return $this;
  }


  public function order_by(string ...$filters): QuerySet{
    if(array_key_exists("ORDER BY", $this->modifiers)){
      throw new \Exception("Multiple order By!");
    }
    $this->modifiers["ORDER BY"] = $filters;
    return $this;
  }



  public function all(): QuerySet{
    $this->filters = array();
    return $this;
  }

  /**
   * @todo:  Implement Relationships
   */

  /*public function union(): QuerySet{

  }*/






  public function count(): int{
    return $this->result->num_rows;
  }


  public function do(){
    list($query, $values) = $this->buildQuery();
    $db = Application::getDb();

    if(count($values) > 0 )
      $result = $db->query($query, ...$values);
    else
      $result = $db->query($query);

    $this->result = $result->fetch_result() ;
    return $this;
  }



  public function buildQuery(){

    $tableName = $this->model::getTableName();
    $columns = (new $this->model)->getColumnList();
    $selectColumns = array_map(array($this, "buildSelectColumns"), $columns);
    //die(print_r($selectColumns));

    $query = sprintf(" SELECT %s FROM %s ", implode(", ", $selectColumns) ,$tableName);
    $values = array();
    $join = array();
    if(count($this->filters)> 0){
      list($whereClause, $values, $join) = $this->buildWhereClause($this->filters);
      array_unshift($join, array("table" => $this->model::getTableName()));
      $query .= $this->buildJoin($join);
      $query .= sprintf(" WHERE %s ", $whereClause);
    }


    if(count($this->modifiers)> 0){
      $modifiers = $this->buildModifiers($this->modifiers);
      $query .= sprintf(" %s", $modifiers);
    }
    //die($query);
    return array($query, $values);
  }


  private function buildSelectColumns($value){
    $tableName = $this->model::getTableName();

    if(is_array($value)){
      return sprintf("%s.%s_%s as %s", $tableName, $value[0], $value[1], $value[0]);
    } else return $tableName.".".$value;
  }


  private function buildJoin(array $join){
    if(count($join) == 1) return "";
      $clause = array();
      for($i = 1; $i < count($join); $i++){
        $clause[] =  sprintf(" %s ON %s.%s_%s = %s.%s",
          $join[$i]["table"],
          $join[$i-1]["table"],
          $join[$i]["column"],
          $join[$i]["pk"],
          $join[$i]["table"],
          $join[$i]["pk"]);
      }
      $return = "JOIN ".implode(" JOIN ", $clause);

      return $return;
  }


  private function buildModifiers(array $modifiers){
   return implode(", ", array_map(function($v,$k){
      if(is_array($v)){
        return $k. " ".implode(', ',$v);
      } else {
        return $k. " ".$v;
      }
    }, $modifiers, array_keys($modifiers))
  );
  }


  private function buildWhereClause($filters){
    $where = "";
    $parameters = array();
    $join = array();
    foreach($filters as $n => $filter){
      foreach($filter as $logic => $values){
        if($n != 0) $where .= sprintf(" %s ", $logic);
        if(is_int(array_key_first($values))){
          $atomic = $this->buildWhereClause($values);
          $where .= sprintf("(%s)", $atomic[0]);
        }
        else {
          $atomic = $this->buildAtomicWhereClause($values);
          $where .= $atomic[0];
        }
        $parameters = array_merge($parameters, $atomic[1]);
        $join = array_merge($join, $atomic[2]);

      }
    }

    return array($where, $parameters, $join);
  }

  private function buildAtomicWhereClause($filters){

      $conditions = array();
      $values = array();
      $join = array();

      array_walk($filters, function(mixed &$value, string $key) use (&$conditions, &$values, &$join){

        $lookup = $this->lookup($key);

        $column = $lookup["column"];
        $condition = $lookup["condition"];

        if(empty($lookup["join"])){
          $tableName = (new $this->model)->getTableName();
        } else {
          $tableName = end($lookup["join"])["table"];
        }
        $map = $this->lookup_map[$condition];
        $values[$column] = ($map["rhs"])($value);
        $conditions[$column] = sprintf("%s.%s %s ?", $tableName, $column, $map["operator"]);
        $join = array_merge($join, $lookup["join"]);
      });

      $clause = implode(" AND ", $conditions);
      return array(0=>$clause, 1=> array_values($values), 2 => $join);
  }


  public function lookup(string $value): array{
    $match = explode("__", $value);
    if(array_key_exists(end($match), $this->lookup_map)){
      $condition = array_pop($match);
    } else {
      $condition = "equals";
    }
    $column = array_pop($match);
    // now match has only joins
    $last = new $this->model;
    $join = array();
    foreach($match as $k){
      $parentName = $last->getRelationshipParentModel($k);
      $last = new $parentName;
      $join[] = array("column"=> $k, "table" => $last->getTableName(), "pk" => $last->getPkName());
    }
    return array(
      "column"    => $column,
      "condition" => $condition,
      "join" => $join
    );
  }


}
