<?php
/**
 * A tiny stand-in for WHMCS's Capsule (Laravel query builder) on SQLite,
 * covering only the calls the SumUp module makes.
 */

namespace WHMCS\Database;

class Capsule
{
    public static $pdo;

    public static function pdo()
    {
        if (!self::$pdo) {
            self::$pdo = new \PDO('sqlite:' . getenv('FAKE_WHMCS_DB'));
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return self::$pdo;
    }

    public static function table($name)
    {
        return new Builder($name);
    }

    public static function schema()
    {
        return new Schema();
    }
}

class Schema
{
    public function hasTable($name)
    {
        $st = Capsule::pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    }

    public function create($name, callable $cb)
    {
        $bp = new Blueprint();
        $cb($bp);
        Capsule::pdo()->exec('CREATE TABLE ' . $name . ' (' . implode(', ', $bp->columns) . ')');
        foreach ($bp->uniques as $col) {
            Capsule::pdo()->exec("CREATE UNIQUE INDEX {$name}_{$col}_u ON {$name} ({$col})");
        }
    }
}

class Blueprint
{
    public $columns = [];
    public $uniques = [];
    private $last;

    private function add($def)
    {
        $this->columns[] = $def;
        $this->last = count($this->columns) - 1;
        return $this;
    }

    public function increments($c) { return $this->add("$c INTEGER PRIMARY KEY AUTOINCREMENT"); }
    public function unsignedInteger($c) { return $this->add("$c INTEGER NOT NULL"); }
    public function string($c, $len = 255) { return $this->add("$c TEXT NOT NULL"); }
    public function decimal($c, $p = 8, $s = 2) { return $this->add("$c REAL NOT NULL"); }
    public function text($c) { return $this->add("$c TEXT NOT NULL"); }
    public function dateTime($c) { return $this->add("$c TEXT NOT NULL"); }

    public function nullable()
    {
        $this->columns[$this->last] = str_replace(' NOT NULL', '', $this->columns[$this->last]);
        return $this;
    }

    public function index() { return $this; }

    public function unique()
    {
        $this->uniques[] = explode(' ', $this->columns[$this->last])[0];
        return $this;
    }
}

class Builder
{
    private $table;
    private $wheres = [];
    private $bindings = [];
    private $order = '';

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function where($col, $val)
    {
        $this->wheres[] = "$col = ?";
        $this->bindings[] = $val;
        return $this;
    }

    public function whereNull($col)
    {
        $this->wheres[] = "$col IS NULL";
        return $this;
    }

    public function orderBy($col, $dir = 'asc')
    {
        $this->order = " ORDER BY $col $dir";
        return $this;
    }

    private function whereSql()
    {
        return $this->wheres ? ' WHERE ' . implode(' AND ', $this->wheres) : '';
    }

    public function get()
    {
        $st = Capsule::pdo()->prepare('SELECT * FROM ' . $this->table . $this->whereSql() . $this->order);
        $st->execute($this->bindings);
        return array_map(function ($r) { return (object) $r; }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function first()
    {
        $rows = $this->get();
        return $rows ? $rows[0] : null;
    }

    public function exists()
    {
        return $this->first() !== null;
    }

    public function insert(array $row)
    {
        $cols = array_keys($row);
        $st = Capsule::pdo()->prepare('INSERT INTO ' . $this->table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($row));
        return true;
    }

    public function update(array $values)
    {
        $sets = [];
        foreach (array_keys($values) as $c) {
            $sets[] = "$c = ?";
        }
        $st = Capsule::pdo()->prepare('UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . $this->whereSql());
        $st->execute(array_merge(array_values($values), $this->bindings));
        return $st->rowCount();
    }
}
