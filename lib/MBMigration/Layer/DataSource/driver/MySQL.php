<?php

namespace MBMigration\Layer\DataSource\driver;

use Exception;
use PDO;
use PDOException;

class MySQL
{
    private PDO $pdo;
    private string $dsn;
    /**
     * @var mixed
     */
    private $password;
    /**
     * @var mixed
     */
    private $userName;

    public function __construct($username, $password, $dbname = 'migration_DB', $host = 'localhost')
    {
        $this->setDSN($host, $dbname);
        $this->setUserName($username);
        $this->setPassword($password);
    }

    /**
     * @throws Exception
     */
    public function doConnect(): MySQL
    {
        try {
            $this->pdo = new PDO(
                $this->getDSN(),
                $this->getUserName(),
                $this->getPassword()
            );

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }

        return $this;
    }

    public function getSingleValue($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchColumn();
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function getAllRows($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll();
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function getColumns($table, $columns = ['*'], $where = '', $params = [])
    {
        $cols = implode(", ", $columns);
        $sql = "SELECT $cols FROM $table";

        if (!empty($where)) {
            $sql .= " WHERE $where";
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll();
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function find($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetch();
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function delete($table, $where, $params = []): bool
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute($params);
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function insert($table, $data)
    {
        $columns = implode(", ", array_keys($data));
        $placeholders = ":" . implode(", :", array_keys($data));

        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }

            $stmt->execute();
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);

                foreach ($data as $key => $value) {
                    $stmt->bindValue(":$key", $value);
                }

                $stmt->execute();
                return $this->pdo->lastInsertId();
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    public function update($table, $data, $where = [])
    {
        $setParts = [];
        $whereParts = [];
        $params = [];

        foreach ($data as $key => $value) {
            $setParts[] = "$key = :set_$key";
            $params[":set_$key"] = $value;
        }

        if (is_array($where)) {
            foreach ($where as $key => $value) {
                $whereParts[] = "$key = :where_$key";
                $params[":where_$key"] = $value;
            }
        } else {
            // Если where - строка, используем её как есть
            $whereParts[] = $where;
        }

        $setClause = implode(", ", $setParts);
        $whereClause = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

        $sql = "UPDATE $table SET $setClause $whereClause";

        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $stmt->rowCount() : false;
        } catch (PDOException $e) {
            // Check if it's a "server has gone away" error
            if ($e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013 || strpos($e->getMessage(), 'server has gone away') !== false) {
                // Try to reconnect
                $this->doConnect();

                // Retry the operation
                $stmt = $this->pdo->prepare($sql);
                $result = $stmt->execute($params);
                return $result ? $stmt->rowCount() : false;
            } else {
                // For other errors, rethrow the exception
                throw $e;
            }
        }
    }

    private function setUserName($username)
    {
        $this->userName = $username;
    }

    private function setDSN($host, $dbname, $charset = 'utf8mb4')
    {
        $this->dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    }

    private function getDSN(): string
    {
        return $this->dsn;
    }

    private function setPassword($password)
    {
        $this->password = $password;
    }

    private function getUserName()
    {
        return $this->userName;
    }

    private function getPassword()
    {
        return $this->password;
    }

}
