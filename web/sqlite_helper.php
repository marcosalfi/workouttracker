<?php
// sqlite_helper.php - PHP 7.x
// Classe helper per SQLite (PDO)

class SqliteDb
{
    private $dbPath = '';
    private $pdo = null;

    // __construct(path opzionale): per compatibilità (new SqliteDb('path'))
    public function __construct($path = '')
    {
        if ($path !== '' && $path !== null) {
            $this->dbPath = (string)$path;
        }
    }

    // init(path): inizializza il path del database
    public function init($path)
    {
        $this->dbPath = (string)$path;
        return $this;
    }

    // opendb(): apre la connessione
    public function openDb()
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if ($this->dbPath === '') {
            throw new RuntimeException('Database path not set');
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO(
            'sqlite:' . $this->dbPath,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        // migliora concorrenza su hosting
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');

        return $this->pdo;
    }

    // closedb()
    public function closeDb()
    {
        $this->pdo = null;
    }

    // squery: scalar query -> stringa
    public function sQuery($sql, array $params = [])
    {
        $stmt = $this->openDb()->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn(0);
        return ($v === false || $v === null) ? '' : (string)$v;
    }

    // query: INSERT/UPDATE/DELETE
    public function query($sql, array $params = [])
    {
        $stmt = $this->openDb()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->rowCount();
    }

    // querydt: equivalente DataTable (array di array associativi)
    public function queryDt($sql, array $params = [])
    {
        $stmt = $this->openDb()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // fetch: prima riga (o null) da una SELECT
    public function fetch($sql, array $params = [])
    {
        $stmt = $this->openDb()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }    

    // transazioni
    public function begin()
    {
        $this->openDb()->beginTransaction();
    }

    public function commit()
    {
        $this->openDb()->commit();
    }

    public function rollback()
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
