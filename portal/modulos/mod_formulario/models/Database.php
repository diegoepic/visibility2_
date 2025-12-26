<?php
class Database
{
    public static function getConnection(): mysqli
    {
        require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
        return $conn;
    }
}
